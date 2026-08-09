import 'dart:convert';
import 'dart:io';

import 'package:agent_connect/core/api_client.dart';
import 'package:agent_connect/data/local_db.dart';
import 'package:agent_connect/services/sync_engine.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The sync engine against a scripted server.
///
/// This is the layer where the one shipped bug lived — the app read
/// `data.validations` from a payload that says `data.assignments`, found
/// nothing, and rendered an empty screen with no error. Nothing about that is
/// visible from the outbox tests, because the outbox was behaving perfectly.
/// So these tests put a real HTTP response in front of the engine and assert
/// what it does with it.
///
/// A MockClient, not a live server: the point is to script the awkward answers
/// — a partial batch, a 401, a socket that dies mid-request — which a real
/// server will not produce on demand.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;

    /*
     * ApiClient reads the bearer token from flutter_secure_storage on EVERY
     * request, and that is a platform channel with nothing behind it in a unit
     * test. Without this the read throws before the HTTP client is ever
     * reached, SyncEngine's blanket catch swallows it, and the tests fail
     * looking like the engine simply did nothing — which is the least
     * informative way a test can fail.
     */
    TestWidgetsFlutterBinding.ensureInitialized();
    FlutterSecureStorage.setMockInitialValues({'api_token': 'test-token'});
  });

  late LocalDb db;

  setUp(() async {
    db = await LocalDb.open(path: inMemoryDatabasePath);
  });

  tearDown(() => db.close());

  /// Builds an engine whose HTTP layer answers with [handler].
  ({SyncEngine engine, List<String> authFailures}) engineWith(
    Future<http.Response> Function(http.Request) handler,
  ) {
    final authFailures = <String>[];

    final engine = SyncEngine(
      db: db,
      api: ApiClient(baseUrl: 'http://test.invalid', client: MockClient(handler)),
      onAuthFailure: () => authFailures.add('signed out'),
    );

    return (engine: engine, authFailures: authFailures);
  }

  http.Response okBatch(Map<String, dynamic> results) => http.Response(
        jsonEncode({'is_success': true, 'accepted': 0, 'rejected': 0, 'results': results}),
        200,
        headers: {'content-type': 'application/json'},
      );

  group('a batch that lands', () {
    test('marks each record synced against the id the server returned', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});

      final e = engineWith((request) async {
        expect(request.url.path, '/api/v1/sync/batch');

        // The record must arrive under its TYPE key, carrying its own uuid.
        final sent = jsonDecode(request.body) as Map<String, dynamic>;
        expect(sent['field_visits'], hasLength(1));
        expect(sent['field_visits'][0]['client_uuid'], uuid);

        return okBatch({
          'field_visits': [
            {'client_uuid': uuid, 'db_id': 77},
          ],
          'errors': [],
        });
      });

      await e.engine.sync();

      expect(await db.pendingBatch(), isEmpty);
      expect((await db.queueSummary()).pending, 0);
      expect(e.engine.state, SyncState.idle);
    });

    test('a per-record refusal fails only that record', () async {
      final good = await db.enqueue('field_visits', {'community_id': 1});
      final bad = await db.enqueue('milk_collections', {'volume': 22});

      final e = engineWith((_) async => okBatch({
            'field_visits': [
              {'client_uuid': good, 'db_id': 5},
            ],
            'milk_collections': [],
            'errors': [
              {
                'type': 'milk_collections',
                'client_uuid': bad,
                'error': 'Not permitted: milk.deliveries.create',
              },
            ],
          }));

      await e.engine.sync();

      final summary = await db.queueSummary();
      expect(summary.pending, 0, reason: 'the good record went');
      expect(summary.failed, 1);

      // And the agent gets the server's wording, not a paraphrase.
      final refused = (await db.unsent()).single;
      expect(refused['client_uuid'], bad);
      expect(refused['error'], contains('Not permitted'));
    });

    test('an id the client never sent is ignored rather than crashing', () async {
      await db.enqueue('field_visits', {'community_id': 1});

      final e = engineWith((_) async => okBatch({
            'field_visits': [
              {'client_uuid': 'a-uuid-from-another-phone', 'db_id': 9},
            ],
            'errors': [],
          }));

      await e.engine.sync();

      // Ours is untouched and will go again, rather than being marked synced
      // against somebody else's row.
      expect((await db.queueSummary()).pending, 1);
    });
  });

  group('failure is classified, not lumped together', () {
    test('a dead socket consumes nothing and leaves the queue intact', () async {
      await db.enqueue('field_visits', {'community_id': 1});

      final e = engineWith((_) async => throw const SocketException('no route to host'));

      await e.engine.sync();

      // The normal case in the field. Nothing marked, nothing lost, no error
      // shown — and critically NOT marked failed, which would need a human.
      expect(e.engine.state, SyncState.offline);
      expect((await db.queueSummary()).pending, 1);
      expect((await db.queueSummary()).failed, 0);
      expect(e.authFailures, isEmpty);
    });

    test('a 401 signs the agent out and keeps their work', () async {
      await db.enqueue('field_visits', {'community_id': 1});

      final e = engineWith((_) async => http.Response(
            jsonEncode({'message': 'Unauthenticated.'}),
            401,
            headers: {'content-type': 'application/json'},
          ));

      await e.engine.sync();

      expect(e.authFailures, ['signed out']);

      // The queue is the agent's morning. An expired token must not cost it.
      expect((await db.queueSummary()).pending, 1);
      expect((await db.queueSummary()).failed, 0);
    });

    test('a 500 is reported but does not fail the records', () async {
      await db.enqueue('field_visits', {'community_id': 1});

      final e = engineWith((_) async => http.Response(
            jsonEncode({'message': 'Server Error'}),
            500,
            headers: {'content-type': 'application/json'},
          ));

      await e.engine.sync();

      expect(e.engine.state, SyncState.failed);
      expect(e.engine.lastError, contains('Server Error'));

      // A server that fell over is not the record's fault.
      expect((await db.queueSummary()).pending, 1);
      expect((await db.queueSummary()).failed, 0);
    });

    test('an HTML error page does not crash the parser', () async {
      await db.enqueue('field_visits', {'community_id': 1});

      // A captive portal in a guest house in Yola answers everything with HTML.
      final e = engineWith((_) async => http.Response('<html>Login required</html>', 200));

      await e.engine.sync();

      expect((await db.queueSummary()).pending, 1);
    });
  });

  group('photographs', () {
    test('are not uploaded until their record has synced', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/never-read.jpg');

      var uploadAttempts = 0;

      final e = engineWith((request) async {
        if (request.url.path.contains('attachments')) uploadAttempts++;

        // The batch itself fails, so the record stays pending.
        return http.Response(jsonEncode({'message': 'Server Error'}), 500,
            headers: {'content-type': 'application/json'});
      });

      await e.engine.sync();

      expect(uploadAttempts, 0,
          reason: 'the server refuses a photo whose record it has not seen');
    });

    test('a missing file is failed permanently, not retried forever', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/definitely-not-here-${DateTime(2026).microsecondsSinceEpoch}.jpg');

      final e = engineWith((_) async => okBatch({
            'field_visits': [
              {'client_uuid': uuid, 'db_id': 3},
            ],
            'errors': [],
          }));

      await e.engine.sync();

      // The record is safe on the server; only the image is gone, and no
      // amount of retrying brings it back.
      expect(await db.sendablePhotos(), isEmpty);
      expect((await db.queueSummary()).photos, 0);
    });
  });

  test('two syncs cannot run at once', () async {
    await db.enqueue('field_visits', {'community_id': 1});

    var calls = 0;

    final e = engineWith((_) async {
      calls++;
      await Future<void>.delayed(const Duration(milliseconds: 40));

      return okBatch({'field_visits': [], 'errors': []});
    });

    // A phone finding a mast reports several transitions in a second.
    await Future.wait([e.engine.sync(), e.engine.sync(), e.engine.sync()]);

    expect(calls, 1, reason: 'overlapping passes would re-send the same batch');
  });
}
