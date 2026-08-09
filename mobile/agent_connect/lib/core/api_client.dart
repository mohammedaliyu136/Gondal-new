import 'dart:convert';
import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Thrown when the server answered, and the answer was no.
///
/// The distinction this class exists to draw: an [ApiException] means the
/// request ARRIVED and was refused, so retrying it unchanged will be refused
/// again. A [SocketException] or a timeout means it may never have arrived, and
/// retrying is exactly right. The sync engine treats the two completely
/// differently, and conflating them either loses records or retries a
/// permission refusal every thirty seconds forever.
class ApiException implements Exception {
  ApiException(this.status, this.message, {this.errors, this.body});

  final int status;
  final String message;
  final Map<String, dynamic>? errors;

  /// The decoded payload, kept because not every 4xx is a failure.
  ///
  /// AUTH-1's "we sent you a code" step is answered with 422 and carries the
  /// single-use `challenge` the next request needs. Throwing that body away
  /// made the second sign-in step unreachable, so the body travels with the
  /// exception and the caller decides what the status means.
  final Map<String, dynamic>? body;

  /// 401/403 — the token is dead or the grant was taken away. Signing the agent
  /// out is the only useful response; retrying is not.
  bool get isAuthFailure => status == 401 || status == 403;

  /// 4xx that is not auth: the record itself is wrong. Retrying will not fix it.
  bool get isPermanent => status >= 400 && status < 500;

  @override
  String toString() => message;
}

/// The one place that knows the server's shape.
class ApiClient {
  ApiClient({required this.baseUrl, http.Client? client})
      : _http = client ?? http.Client();

  final String baseUrl;
  final http.Client _http;
  final _storage = const FlutterSecureStorage();

  static const _tokenKey = 'api_token';
  static const _deviceKey = 'device_token';

  /// Field connections are slow, not absent — a 90-second window at the top of
  /// a hill is a real thing. Long enough to finish a batch, short enough that
  /// the app does not appear frozen.
  static const _timeout = Duration(seconds: 45);

  Future<String?> get token => _storage.read(key: _tokenKey);
  Future<String?> get deviceToken => _storage.read(key: _deviceKey);

  Future<void> saveToken(String token) => _storage.write(key: _tokenKey, value: token);

  /// AUTH-2 — the trust token from a previous sign-in on this phone, so the
  /// agent is not asked for an emailed code in a place with no email.
  Future<void> saveDeviceToken(String? value) async {
    if (value != null) await _storage.write(key: _deviceKey, value: value);
  }

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  Future<Map<String, String>> _headers({bool json = true}) async {
    final t = await token;

    return {
      'Accept': 'application/json',
      if (json) 'Content-Type': 'application/json',
      if (t != null) 'Authorization': 'Bearer $t',
    };
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) async {
    final uri = Uri.parse('$baseUrl$path').replace(queryParameters: query);
    final response = await _http.get(uri, headers: await _headers()).timeout(_timeout);

    return _decode(response);
  }

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) async {
    final response = await _http
        .post(Uri.parse('$baseUrl$path'), headers: await _headers(), body: jsonEncode(body))
        .timeout(_timeout);

    return _decode(response);
  }

  /// Multipart, for a photograph. Streamed rather than loaded whole so a 2 MB
  /// image does not sit in memory twice on a low-end phone.
  Future<Map<String, dynamic>> upload(
    String path, {
    required File file,
    required Map<String, String> fields,
    String fieldName = 'photo',
  }) async {
    final request = http.MultipartRequest('POST', Uri.parse('$baseUrl$path'))
      ..headers.addAll(await _headers(json: false))
      ..fields.addAll(fields)
      ..files.add(await http.MultipartFile.fromPath(fieldName, file.path));

    final streamed = await request.send().timeout(_timeout);

    return _decode(await http.Response.fromStream(streamed));
  }

  Map<String, dynamic> _decode(http.Response response) {
    final Map<String, dynamic> body;

    try {
      body = jsonDecode(response.body) as Map<String, dynamic>;
    } on FormatException {
      // An HTML error page, a captive portal, a proxy. Not a server answer.
      throw ApiException(response.statusCode, 'The server sent something unreadable.');
    }

    if (response.statusCode >= 400) {
      throw ApiException(
        response.statusCode,
        (body['message'] ?? body['error'] ?? 'Request refused.').toString(),
        errors: body['errors'] as Map<String, dynamic>?,
        body: body,
      );
    }

    return body;
  }
}
