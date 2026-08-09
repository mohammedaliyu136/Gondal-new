import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../data/local_db.dart';

enum SyncState { idle, running, offline, failed }

/// Draining the outbox.
///
/// THE ORDER IS NOT NEGOTIABLE: records first, photographs second. The server
/// resolves a photo to its record through the client_uuid in
/// `mobile_sync_records`, so a photo sent before its record is refused —
/// retryably, but it is still a wasted upload on a connection that may last
/// ninety seconds. `LocalDb.sendablePhotos()` enforces this with a join rather
/// than a hope.
///
/// WHAT COUNTS AS FAILURE. Three outcomes, and they are not the same thing:
///
///   Network died          — nothing is marked. The batch is still pending and
///                           will go again. This is the normal case in the field
///                           and must never consume a record.
///   Server refused a row  — that row is marked `failed` with the reason, and
///                           the rest of the batch is unaffected. The endpoint
///                           returns per-record results precisely so one bad
///                           record cannot poison a morning's work.
///   Token rejected        — everything stops and the agent is signed out.
///                           Retrying a 401 forever just drains the battery.
///
/// SERIALISED BY [_running]. Connectivity events arrive in bursts — a phone
/// finding a mast reports several transitions in a second — and two passes
/// running together would send the same batch twice. The server's idempotency
/// would absorb it, but the upload would not: it is the agent's data bundle.
class SyncEngine extends ChangeNotifier {
  SyncEngine({required LocalDb db, required ApiClient api, required this.onAuthFailure})
      : _db = db,
        _api = api;

  final LocalDb _db;
  final ApiClient _api;
  final VoidCallback onAuthFailure;

  /// The signed-in agent's email, so the queue drains under the right person.
  /// Set on sign-in and cleared on sign-out; see `LocalDb.pendingBatch`.
  String owner = '';

  StreamSubscription<List<ConnectivityResult>>? _watch;
  Timer? _ticker;
  bool _running = false;

  SyncState state = SyncState.idle;
  String? lastError;
  DateTime? lastSuccessAt;

  ({int pending, int failed, int photos}) queue = (pending: 0, failed: 0, photos: 0);

  /// Sync when a connection appears, and every so often regardless.
  ///
  /// The timer is not redundant with the connectivity stream. `connectivity_plus`
  /// reports that an INTERFACE came up, which on a rural network routinely means
  /// a mast the phone cannot actually route through. The periodic pass is what
  /// eventually gets the data out when the transition was missed or lied.
  void start() {
    _watch = Connectivity().onConnectivityChanged.listen((results) {
      if (!results.contains(ConnectivityResult.none)) {
        unawaited(sync(reason: 'connection returned'));
      }
    });

    _ticker = Timer.periodic(const Duration(minutes: 5), (_) => sync(reason: 'periodic'));

    unawaited(refreshCounts());
  }

  @override
  void dispose() {
    _watch?.cancel();
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> refreshCounts() async {
    queue = await _db.queueSummary();
    notifyListeners();
  }

  Future<void> sync({String reason = 'manual'}) async {
    if (_running) return;

    _running = true;
    state = SyncState.running;
    lastError = null;
    notifyListeners();

    try {
      await _pushRecords();
      await _pushPhotos();

      state = SyncState.idle;
      lastSuccessAt = DateTime.now();
    } on ApiException catch (e) {
      if (e.isAuthFailure) {
        state = SyncState.failed;
        lastError = 'Signed out by the server. Sign in again.';
        onAuthFailure();
      } else {
        state = SyncState.failed;
        lastError = e.message;
      }
    } on SocketException {
      // Expected, constantly, and not an error the agent needs to read.
      state = SyncState.offline;
    } on TimeoutException {
      state = SyncState.offline;
    } catch (e) {
      state = SyncState.failed;
      lastError = e.toString();
    } finally {
      _running = false;
      await refreshCounts();
    }
  }

  Future<void> _pushRecords() async {
    final batch = await _db.pendingBatch(owner: owner);

    if (batch.isEmpty) return;

    for (final records in batch.values) {
      for (final record in records) {
        await _db.countAttempt(record['client_uuid'] as String);
      }
    }

    final response = await _api.post('/api/v1/sync/batch', batch);
    final results = (response['results'] as Map?) ?? {};

    // Everything that landed, by type.
    for (final entry in results.entries) {
      if (entry.key == 'errors') continue;

      for (final row in (entry.value as List? ?? [])) {
        final map = row as Map;
        final id = map['db_id'];

        if (id is int) {
          await _db.markSynced(map['client_uuid'] as String, id);
        }
      }
    }

    /*
     * And everything that was refused. These are per-record and permanent —
     * a permission the agent does not hold, a farmer the server does not have.
     * Marked `failed` so the agent sees them on the Unsent screen with the
     * server's own wording, rather than watching a counter never reach zero.
     */
    for (final row in (results['errors'] as List? ?? [])) {
      final map = row as Map;
      final uuid = map['client_uuid'];

      if (uuid is String) {
        await _db.markFailed(uuid, (map['error'] ?? 'Refused.').toString());
      }
    }
  }

  Future<void> _pushPhotos() async {
    // A few at a time: each is a megabyte, and a batch of thirty on a rural
    // connection is thirty chances to time out as one unit.
    final photos = await _db.sendablePhotos(limit: 5);

    for (final photo in photos) {
      final id = photo['id'] as int;
      final file = File(photo['file_path'] as String);

      if (!file.existsSync()) {
        // The OS reclaimed the cache directory. The record itself is safe and
        // already on the server; only the image is gone, and no amount of
        // retrying will bring it back.
        await _db.markPhotoFailed(id, 'The photo file is no longer on the phone.', permanent: true);
        continue;
      }

      try {
        await _api.upload(
          '/api/v1/attachments',
          file: file,
          fields: {
            'client_uuid': photo['client_uuid'] as String,
            if (photo['caption'] != null) 'caption': photo['caption'] as String,
          },
        );

        await _db.markPhotoSynced(id);

        // Reclaim the space. The server has it now, and a field phone fills up.
        try {
          await file.delete();
        } catch (_) {
          // Not worth failing a successful upload over.
        }
      } on ApiException catch (e) {
        if (e.isAuthFailure) rethrow;

        // A 422 here is usually "the record has not reached the server yet",
        // which the next pass fixes on its own — so it is not permanent unless
        // the server has told us the photo itself is unacceptable.
        final unacceptable = e.message.contains('not a photograph') ||
            e.message.contains('larger than');

        await _db.markPhotoFailed(id, e.message, permanent: unacceptable);
      }
      // A network failure propagates: if this upload could not reach the
      // server, neither will the next four, and trying them wastes the window.
    }
  }
}
