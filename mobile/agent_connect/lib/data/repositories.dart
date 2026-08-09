import 'dart:convert';

import '../core/api_client.dart';
import 'local_db.dart';

/// Step 1 of AUTH-1 can end three ways, and the UI branches on all three.
enum LoginOutcome { signedIn, codeRequired, refused }

class LoginResult {
  LoginResult(this.outcome, {this.message, this.user, this.challenge, this.maskedEmail});

  final LoginOutcome outcome;
  final String? message;
  final Map<String, dynamic>? user;

  /// AUTH-1 step 2 is keyed on THIS, not on the email address.
  ///
  /// The server mints a single-use random token, caches the pending sign-in
  /// under a hash of it, and forgets it once consumed — so it cannot be
  /// reconstructed on the phone and cannot be replaced by anything the agent
  /// types. Dropping it here is what made the second step unreachable.
  final String? challenge;

  /// `j••••@gondalfulbe.ng` — shown so the agent knows which inbox to open.
  final String? maskedEmail;
}

/// Sign-in, two steps, with the device-trust shortcut.
///
/// AUTH-1 is password then emailed code. That second step is a problem this app
/// cannot wish away: an agent in Gengle has no inbox. AUTH-2's device token is
/// the answer the backend already provides — the phone proves it is the same
/// phone that completed a code once, and is not asked again. So the token is
/// stored on the FIRST successful verify and replayed on every later sign-in,
/// and the code screen appears once per device rather than once per morning.
class AuthRepository {
  AuthRepository(this._api, this._db);

  final ApiClient _api;
  final LocalDb _db;

  /// Named so the server can tell one agent's handsets apart in the device list.
  static const _deviceName = 'AgentConnect';

  Future<LoginResult> login(String email, String password) async {
    /*
     * The server answers `code_required` with HTTP 422, so this is not an
     * error path — it is the ordinary second step of AUTH-1, and catching it
     * as a failure is what would send an agent back to the password screen
     * with "those details were not accepted".
     */
    late final Map<String, dynamic> body;

    try {
      body = await _api.post('/api/v1/auth/login', {
        'email': email,
        'password': password,
        'device_token': await _api.deviceToken,
        'device_name': _deviceName,
      });
    } on ApiException catch (e) {
      final data = e.body?['data'];

      if (e.body?['status'] == 'code_required' && data is Map) {
        return LoginResult(
          LoginOutcome.codeRequired,
          message: e.message,
          challenge: data['challenge']?.toString(),
          maskedEmail: data['masked_email']?.toString(),
        );
      }

      if (e.isPermanent) {
        return LoginResult(LoginOutcome.refused, message: e.message);
      }

      rethrow;
    }

    // Trusted device: the server skipped the code and issued a token outright.
    if (body['data']?['token'] != null) {
      return _accept(body);
    }

    final data = body['data'];

    return LoginResult(
      LoginOutcome.codeRequired,
      message: body['message']?.toString(),
      challenge: data is Map ? data['challenge']?.toString() : null,
      maskedEmail: data is Map ? data['masked_email']?.toString() : null,
    );
  }

  /// AUTH-1 step 2, keyed on the challenge the login step returned.
  ///
  /// `remember_device: true` is what makes this the LAST time the agent is
  /// asked. The server only mints an AUTH-2 device token when it is set
  /// (MobileSigninService::verify), so omitting it means the code screen
  /// returns every morning — for an agent in Gengle with no inbox, that is the
  /// failure the whole device-trust design exists to prevent.
  Future<LoginResult> verify(String challenge, String code) async {
    try {
      final body = await _api.post('/api/v1/auth/verify', {
        'challenge': challenge,
        'code': code,
        'remember_device': true,
        'device_name': _deviceName,
      });

      if (body['is_success'] != true) {
        return LoginResult(LoginOutcome.refused, message: body['message']?.toString());
      }

      return _accept(body);
    } on ApiException catch (e) {
      if (e.isPermanent) {
        return LoginResult(LoginOutcome.refused, message: e.message);
      }

      rethrow;
    }
  }

  Future<LoginResult> _accept(Map<String, dynamic> body) async {
    final data = body['data'] as Map<String, dynamic>;

    await _api.saveToken(data['token'] as String);
    await _api.saveDeviceToken(data['device_token'] as String?);

    /*
     * The sign-in payload is FLAT — agent_email, agent_name, agent_code,
     * agent_role — not a nested `user` object. Reading data['user'] left the
     * cached profile permanently null and the home screen falling back to
     * "AgentConnect" instead of the agent's name.
     */
    final user = <String, dynamic>{
      'name': data['agent_name'],
      'email': data['agent_email'],
      'code': data['agent_code'],
      'role': data['agent_role'],
    }..removeWhere((_, value) => value == null);

    if (user.isNotEmpty) await _db.cache('me', jsonEncode(user));

    return LoginResult(LoginOutcome.signedIn, user: user);
  }

  Future<void> signOut() async {
    try {
      await _api.post('/api/v1/auth/logout', {});
    } catch (_) {
      // Signing out locally must work with no network. The server token expires
      // on its own; refusing to sign out because a request failed would strand
      // an agent on a phone they need to hand to a colleague.
    }

    await _api.clearToken();
  }

  Future<bool> get isSignedIn async => (await _api.token) != null;

  Future<Map<String, dynamic>?> cachedUser() async {
    final raw = await _db.cached('me');

    return raw == null ? null : jsonDecode(raw) as Map<String, dynamic>;
  }
}

/// Everything the field forms need, cached so the app opens usefully after a
/// week with no signal.
///
/// Each getter answers from the network when it can and from the cache when it
/// cannot — never an empty list because a request failed. An agent opening the
/// visit form in Gengle needs the community picker to work, and the communities
/// have not changed since Yola.
class FieldDataRepository {
  FieldDataRepository(this._api, this._db);

  final ApiClient _api;
  final LocalDb _db;

  Future<Map<String, dynamic>> formOptions() =>
      _fetch('/api/v1/agent/form-options', 'form_options');

  /// Who the agent is and what they may do, cached.
  ///
  /// The home screen builds itself from `permissions` rather than offering
  /// every module to everybody: an agent without `can_record_milk_intake` who
  /// taps "Record a delivery" would fill in a whole form and only learn at sync
  /// time — possibly hours later, in a different village — that the server was
  /// never going to accept it.
  Future<Map<String, dynamic>> me() => _fetch('/api/v1/me', 'me_full');

  /// All the farmers this agent can see, for the delivery picker.
  Future<List<dynamic>> farmers({int? communityId}) async {
    final body = await _fetch(
      '/api/v1/farmers/search',
      communityId == null ? 'farmers_all' : 'farmers_$communityId',
      query: {
        if (communityId != null) 'community': '$communityId',
        'per_page': '100',
      },
    );

    final data = body['data'];

    return data is List ? data : const [];
  }

  /// BR-36 — the revalidations M&E has assigned to this worker.
  ///
  /// The server nests them under `data.assignments`. That exact key is the
  /// whole contract: an earlier version of this method guessed `data` and
  /// `data.validations`, found neither, and returned an empty list — so the
  /// main screen of the app rendered "Nothing assigned to you" while six
  /// assignments sat waiting. It failed silently, which is why this reads one
  /// documented key and falls back to nothing rather than hunting.
  Future<List<dynamic>> assignedValidations() async {
    final body = await _fetch('/api/v1/validations', 'validations');
    final assignments = (body['data'] as Map<String, dynamic>?)?['assignments'];

    return assignments is List ? assignments : const [];
  }

  /// The farmers in one community, cached per community.
  ///
  /// Cached by community rather than as one list because that is the unit a
  /// visit is recorded against, and because an agent covering three communities
  /// should not have to download a fourth's register to open a form. The cache
  /// is what makes the picker work in Gengle; the register does not change
  /// between Yola and the field.
  Future<List<dynamic>> farmersIn(int communityId) async {
    final body = await _fetch(
      '/api/v1/farmers/search',
      'farmers_$communityId',
      query: {'community': '$communityId', 'per_page': '100'},
    );

    final data = body['data'];

    return data is List ? data : const [];
  }

  Future<Map<String, dynamic>> _fetch(
    String path,
    String cacheKey, {
    Map<String, String>? query,
  }) async {
    try {
      final body = await _api.get(path, query: query);

      await _db.cache(cacheKey, jsonEncode(body));

      return body;
    } catch (_) {
      final cached = await _db.cached(cacheKey);

      if (cached == null) rethrow;

      return jsonDecode(cached) as Map<String, dynamic>;
    }
  }
}

/// Capture. Every method here writes locally and returns immediately — nothing
/// in the UI ever awaits the network.
class CaptureRepository {
  CaptureRepository(this._db);

  final LocalDb _db;

  /// The signed-in agent's email, stamped on every capture so the queue can
  /// only ever be sent under the person who made it.
  String owner = '';

  /// A revalidation answer. `validation_id` is the assignment it responds to;
  /// the server refuses a submission that answers no assignment, because an
  /// unrequested validation is an edit wearing a costume.
  Future<String> submitValidation({
    required int validationId,
    required int farmerId,
    required String outcome,
    String? findings,
    String? phone,
    int? herdSize,
    int? lactatingCount,
    Map<String, dynamic>? fix,
  }) {
    return _db.enqueue('farmer_validations', owner: owner, {
      'validation_id': validationId,
      'farmer_db_id': farmerId,
      'outcome': outcome,
      if (findings != null && findings.isNotEmpty) 'findings': findings,
      if (phone != null && phone.isNotEmpty) 'phone': phone,
      if (herdSize != null) 'herd_size': herdSize,
      if (lactatingCount != null) 'lactating_count': lactatingCount,
      ...?fix,
    });
  }

  Future<String> logFieldVisit({
    required int communityId,
    required String visitDate,
    List<String> topics = const [],
    String? notes,
    List<int> farmerIds = const [],
    Map<String, dynamic>? fix,
  }) {
    return _db.enqueue('field_visits', owner: owner, {
      'community_id': communityId,
      'visit_date': visitDate,
      'topics': topics,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
      'farmers': farmerIds.map((id) => {'farmer_id': id}).toList(),
      ...?fix,
    });
  }

  /// A farmer's milk, at a point, on a morning.
  ///
  /// The volumes go as STRINGS. The server stores litres as decimal(10,2) and
  /// BR-6 makes accepted = presented − rejected; sending 22.4 as a double
  /// invites a float that serialises as 22.399999999999999, and the arithmetic
  /// that follows it is a farmer's payment.
  ///
  /// `delivered_at` is the instant the agent captured, not the instant this
  /// syncs — ARCH-9, and the reason a delivery recorded at 06:40 and synced at
  /// noon is not judged late against the 07:00 cut-off.
  Future<String> recordDelivery({
    required int collectionPointId,
    required int farmerId,
    required String litresPresented,
    String litresRejected = '0',
    int? rejectionReasonId,
    int? containers,
    String? notes,
    required DateTime deliveredAt,
    bool cutoffOverride = false,
    String? cutoffOverrideReason,
  }) {
    return _db.enqueue('milk_collections', owner: owner, {
      'collection_point_id': collectionPointId,
      'farmer_db_id': farmerId,
      'volume': litresPresented,
      'litres_rejected': litresRejected,
      if (rejectionReasonId != null) 'rejection_reason_id': rejectionReasonId,
      if (containers != null) 'containers': containers,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
      'delivered_at': deliveredAt.toUtc().toIso8601String(),
      if (cutoffOverride) 'cutoff_override': true,
      if (cutoffOverride && cutoffOverrideReason != null && cutoffOverrideReason.isNotEmpty)
        'cutoff_override_reason': cutoffOverrideReason,
    });
  }

  Future<void> attachPhoto(String clientUuid, String path, {String? caption}) =>
      _db.attachPhoto(clientUuid, path, caption: caption);
}
