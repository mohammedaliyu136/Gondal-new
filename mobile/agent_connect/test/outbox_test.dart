import 'dart:convert';

import 'package:agent_connect/data/local_db.dart';
import 'package:agent_connect/data/repositories.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// The outbox state machine.
///
/// This is the part of the app that cannot be re-run: a phone in Gengle records
/// eleven visits and the agent walks away, and whatever this code does is what
/// the register ends up believing. So the tests here are about the properties
/// that lose or duplicate work, not about widgets.
///
/// Runs the REAL schema through sqflite_common_ffi rather than a fake, because
/// the ordering guarantee that matters most — photographs never leave before
/// their record — is enforced by a SQL join, and a mock would happily agree
/// with a join that was wrong.
void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  late LocalDb db;

  setUp(() async {
    db = await LocalDb.open(path: inMemoryDatabasePath);
  });

  // sqflite caches open databases BY PATH, and every test here opens the same
  // `:memory:`. Without this the second test inherits the first one's rows and
  // the failures read as logic bugs rather than as shared state.
  tearDown(() => db.close());

  group('client_uuid', () {
    test('is minted once and carried inside the payload', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});

      final batch = await db.pendingBatch();
      final record = batch['field_visits']!.single;

      // The server reads the id from the RECORD, not the envelope, so it has to
      // be in there — and it has to be the same one the row is keyed on.
      expect(record['client_uuid'], uuid);
    });

    test('is different for every capture', () async {
      final a = await db.enqueue('field_visits', {'community_id': 1});
      final b = await db.enqueue('field_visits', {'community_id': 1});

      expect(a, isNot(b));
    });

    test('survives a retry unchanged', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});

      await db.markFailed(uuid, 'Not permitted.');
      await db.retry(uuid);

      final batch = await db.pendingBatch();

      // If a retry re-minted the id, a record that HAD landed server-side
      // before the error reached us would be written a second time.
      expect(batch['field_visits']!.single['client_uuid'], uuid);
    });
  });

  group('queue transitions', () {
    test('a pending record is offered for sending, a synced one is not', () async {
      final uuid = await db.enqueue('farmer_validations', {'validation_id': 7});

      expect((await db.pendingBatch())['farmer_validations'], hasLength(1));

      await db.markSynced(uuid, 42);

      expect(await db.pendingBatch(), isEmpty);
      expect((await db.queueSummary()).pending, 0);
    });

    test('a refusal keeps the record and its reason', () async {
      final uuid = await db.enqueue('milk_collections', {'volume': 22});

      await db.markFailed(uuid, 'Not permitted: milk.deliveries.create');

      // Kept, not dropped. The agent did the work; only they can decide what
      // happens to it.
      final rows = await db.unsent();
      expect(rows, hasLength(1));
      expect(rows.single['error'], contains('Not permitted'));
      expect((await db.queueSummary()).failed, 1);

      // And it is no longer retried automatically.
      expect(await db.pendingBatch(), isEmpty);
      expect(uuid, isNotEmpty);
    });

    test('groups the batch by type, the way the endpoint expects', () async {
      await db.enqueue('field_visits', {'community_id': 1});
      await db.enqueue('field_visits', {'community_id': 2});
      await db.enqueue('farmer_validations', {'validation_id': 3});

      final batch = await db.pendingBatch();

      expect(batch.keys, containsAll(['field_visits', 'farmer_validations']));
      expect(batch['field_visits'], hasLength(2));
      expect(batch['farmer_validations'], hasLength(1));
    });

    test('discarding removes the record and its photographs together', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/a.jpg');

      await db.discard(uuid);

      expect(await db.unsent(), isEmpty);
      final summary = await db.queueSummary();
      expect(summary.photos, 0, reason: 'an orphaned photo can never be sent');
    });
  });

  group('photographs never leave before their record', () {
    test('a photo whose record is still pending is not sendable', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/visit.jpg');

      // The server resolves a photo to its record THROUGH client_uuid, so this
      // upload would be refused. Sending it anyway burns a 90-second window.
      expect(await db.sendablePhotos(), isEmpty);
    });

    test('it becomes sendable the moment the record syncs', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/visit.jpg', caption: 'Households');

      await db.markSynced(uuid, 99);

      final sendable = await db.sendablePhotos();
      expect(sendable, hasLength(1));
      expect(sendable.single['client_uuid'], uuid);
      expect(sendable.single['caption'], 'Households');
    });

    test('a refused record never lets its photo go', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/visit.jpg');

      await db.markFailed(uuid, 'Not permitted.');

      expect(await db.sendablePhotos(), isEmpty);
    });

    test('a photo that cannot be retried is marked failed, not left pending', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/visit.jpg');
      await db.markSynced(uuid, 1);

      final photo = (await db.sendablePhotos()).single;

      await db.markPhotoFailed(photo['id'] as int, 'That file is not a photograph.',
          permanent: true);

      expect(await db.sendablePhotos(), isEmpty,
          reason: 'a permanently rejected photo must stop being offered');
    });

    test('a retryable photo failure leaves it queued', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/visit.jpg');
      await db.markSynced(uuid, 1);

      final photo = (await db.sendablePhotos()).single;

      await db.markPhotoFailed(photo['id'] as int, 'Connection lost.');

      expect(await db.sendablePhotos(), hasLength(1));
    });
  });

  group('the unsent list', () {
    test('puts refusals above records that are merely waiting', () async {
      final waiting = await db.enqueue('field_visits', {'community_id': 1});
      final refused = await db.enqueue('field_visits', {'community_id': 2});

      await db.markFailed(refused, 'Not permitted.');

      final rows = await db.unsent();

      // The refusal is the only one that needs a human. Burying it under
      // twenty pending rows is how it goes unread.
      expect(rows.first['client_uuid'], refused);
      expect(rows.last['client_uuid'], waiting);
    });

    test('counts the photographs still attached to each record', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.attachPhoto(uuid, '/tmp/a.jpg');
      await db.attachPhoto(uuid, '/tmp/b.jpg');

      final row = (await db.unsent()).single;

      expect(row['photos_waiting'], 2);
    });

    test('a fully synced record is not in it', () async {
      final uuid = await db.enqueue('field_visits', {'community_id': 1});
      await db.markSynced(uuid, 5);

      expect(await db.unsent(), isEmpty);
    });
  });

  group('cache', () {
    test('serves the last body written, so a form opens with no signal', () async {
      await db.cache('form_options', jsonEncode({'data': {'communities': [1, 2]}}));

      final raw = await db.cached('form_options');

      expect(jsonDecode(raw!)['data']['communities'], [1, 2]);
    });

    test('is empty rather than wrong before anything has been fetched', () async {
      expect(await db.cached('form_options'), isNull);
    });
  });

  group('milk collection', () {
    test('a delivery queues under its own type with volumes as strings', () async {
      final capture = CaptureRepository(db)..owner = 'musa.ibrahim@gondalfulbe.ng';

      final uuid = await capture.recordDelivery(
        collectionPointId: 1,
        farmerId: 7,
        litresPresented: '22.40',
        litresRejected: '2.40',
        rejectionReasonId: 1,
        containers: 2,
        deliveredAt: DateTime.utc(2026, 8, 9, 6, 40),
      );

      final record = (await capture_batch(db))['milk_collections']!.single;

      expect(record['client_uuid'], uuid);
      expect(record['collection_point_id'], 1);
      expect(record['farmer_db_id'], 7);

      // STRINGS. The server stores decimal(10,2) and BR-6 subtracts these; a
      // double would arrive as 22.399999999999999 and the farmer is paid on it.
      expect(record['volume'], isA<String>());
      expect(record['volume'], '22.40');
      expect(record['litres_rejected'], '2.40');

      expect(record['rejection_reason_id'], 1);
      expect(record['containers'], 2);

      // ARCH-9 — the instant of the intake, not of the sync.
      expect(record['delivered_at'], startsWith('2026-08-09T06:40'));
    });

    test('an unrejected delivery carries no reason and no override', () async {
      final capture = CaptureRepository(db);

      await capture.recordDelivery(
        collectionPointId: 1,
        farmerId: 7,
        litresPresented: '18',
        deliveredAt: DateTime.utc(2026, 8, 9, 6, 15),
      );

      final record = (await capture_batch(db))['milk_collections']!.single;

      expect(record.containsKey('rejection_reason_id'), isFalse);
      expect(record.containsKey('cutoff_override'), isFalse,
          reason: 'the agent does not hold that authority and must not claim it');
      expect(record['litres_rejected'], '0');
    });

    test('the agent who captured it owns it', () async {
      final mine = CaptureRepository(db)..owner = 'musa.ibrahim@gondalfulbe.ng';
      final theirs = CaptureRepository(db)..owner = 'auwal.sule@gondalfulbe.ng';

      await mine.recordDelivery(
        collectionPointId: 1, farmerId: 7, litresPresented: '10',
        deliveredAt: DateTime.utc(2026, 8, 9, 6, 0),
      );
      await theirs.recordDelivery(
        collectionPointId: 2, farmerId: 8, litresPresented: '11',
        deliveredAt: DateTime.utc(2026, 8, 9, 6, 5),
      );

      // A phone handed between agents must not send one under the other.
      final musa = await db.pendingBatch(owner: 'musa.ibrahim@gondalfulbe.ng');
      expect(musa['milk_collections'], hasLength(1));
      expect(musa['milk_collections']!.single['farmer_db_id'], 7);
    });
  });
}

/// The pending batch, for readability in the milk tests.
Future<Map<String, List<Map<String, dynamic>>>> capture_batch(LocalDb db) => db.pendingBatch();
