import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'reading_assignments.dart';

/// The server the app talks to. A release build must be built with
/// `--dart-define=API_BASE_URL=https://<domain>`; only a debug build falls back
/// to the Android emulator's address of the development server, so a release
/// can never ship pointing at a developer machine.
const apiBaseUrl = String.fromEnvironment('API_BASE_URL',
    defaultValue:
        bool.fromEnvironment('dart.vm.product') ? '' : 'http://10.0.2.2:8000');

class ApiException implements Exception {
  const ApiException(this.message, this.statusCode, {this.errors = const {}});
  final String message;
  final int statusCode;
  final Map<String, dynamic> errors;
  bool get isNetwork => statusCode == 0;
  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({HttpClient? httpClient}) : _client = httpClient ?? HttpClient();
  final HttpClient _client;
  String? token;
  void Function()? onUnauthenticated;

  Future<Map<String, dynamic>> login(String username, String password,
      {bool remember = false}) async {
    final result = await _request('POST', '/api/mobile/login', body: {
      'username': username,
      'password': password,
      'remember': remember
    });
    token = result['token'] as String;
    return result;
  }

  Future<Map<String, dynamic>> me() => _request('GET', '/api/mobile/me');
  Future<Map<String, dynamic>> readingAssignments() =>
      _request('GET', '/api/mobile/reading-assignments');
  Future<Map<String, dynamic>> rosterPage(int page) async =>
      assignedReadingRoster(await readingAssignments());
  Future<Map<String, dynamic>> sendReading(Map<String, dynamic> reading) async {
    if (reading['assignment_id'] == null)
      return _request('POST', '/api/mobile/readings', body: reading);
    final response =
        await _request('POST', '/api/mobile/reading-assignments/sync', body: {
      'readings': [
        {
          'operation_id': reading['mobile_operation_id'],
          'assignment_id': reading['assignment_id'],
          'authorization': reading['authorization'],
          'subscription_id': reading['subscription_id'],
          'current_reading': reading['current_reading'],
          'captured_at': reading['captured_at'],
          'notes': reading['notes'],
        }
      ]
    });
    final result =
        Map<String, dynamic>.from((response['results'] as List).single as Map);
    if (result['status'] == 'rejected')
      throw ApiException('${result['message'] ?? 'رفض الخادم القراءة.'}', 422);
    return {
      ...result,
      'status': result['status'] == 'accepted' ? 'pending' : result['status']
    };
  }

  Future<Map<String, dynamic>> readingDistribution({int? branchId}) =>
      _request('GET', '/api/mobile/reading-distribution',
          query: {if (branchId != null) 'branch_id': '$branchId'});
  Future<Map<String, dynamic>> distributeReadings(
          int cycleId, Map<String, dynamic> data) =>
      _request('POST', '/api/mobile/reading-distribution/$cycleId/assign',
          body: data);
  Future<Map<String, dynamic>> approveReadingDistribution(int cycleId) =>
      _request('POST', '/api/mobile/reading-distribution/$cycleId/approve');
  Future<Map<String, dynamic>> weeklyReadings(
          {String search = '',
          String? week,
          String subscriptionStatus = 'active',
          int page = 1}) =>
      _request('GET', '/api/mobile/readings', query: {
        'search': search,
        'subscription_status': subscriptionStatus,
        'page': '$page',
        if (week != null) 'week': week,
      });
  Future<Map<String, dynamic>> findCollectionSubscribers(String search,
          {int page = 1}) =>
      _request('GET', '/api/mobile/collections/subscriptions',
          query: {'search': search, 'page': '$page'});
  Future<Map<String, dynamic>> collectionSubscriber(int id) =>
      _request('GET', '/api/mobile/collections/subscriptions/$id');
  Future<Map<String, dynamic>> sendAdjustment(
          int id, Map<String, dynamic> adjustment) =>
      _request('POST', '/api/mobile/collections/subscriptions/$id/adjustments',
          body: adjustment);
  Future<Map<String, dynamic>> subscriberTransactions(int id, {int page = 1}) =>
      _request('GET', '/api/mobile/collections/subscriptions/$id/transactions',
          query: {'page': '$page'});
  Future<Map<String, dynamic>> sendSubscriberMessage(
          int id, Map<String, dynamic> message) =>
      _request('POST', '/api/mobile/collections/subscriptions/$id/messages',
          body: message);
  Future<Map<String, dynamic>> messageSubscribers(String search,
          {int page = 1}) =>
      _request('GET', '/api/mobile/messages/subscriptions',
          query: {'search': search, 'page': '$page'});
  Future<Map<String, dynamic>> subscriberMessageHistory(int id,
          {int page = 1}) =>
      _request('GET', '/api/mobile/collections/subscriptions/$id/messages',
          query: {'page': '$page'});
  Future<Map<String, dynamic>> collectionsToday() =>
      _request('GET', '/api/mobile/collections');
  Future<Map<String, dynamic>> cashBoxes() =>
      _request('GET', '/api/mobile/cash-boxes');
  Future<Map<String, dynamic>> sendCollection(
          Map<String, dynamic> collection) =>
      _request('POST', '/api/mobile/collections', body: collection);
  Future<Map<String, dynamic>> sendSplitPayment(Map<String, dynamic> payment) =>
      _request('POST', '/api/mobile/split-payments', body: payment);

  Future<Map<String, dynamic>> _decodeResponse(
      HttpClientResponse response, String? requestToken) async {
    if (response.statusCode == 401 &&
        requestToken != null &&
        token == requestToken) {
      onUnauthenticated?.call();
    }
    if (response.statusCode >= 300 && response.statusCode < 400) {
      await response.drain<void>();
      throw const ApiException(
          'رفض التطبيق إعادة توجيه الاتصال. تحقق من عنوان الخادم الآمن.', 0);
    }
    final decoded = jsonDecode(await utf8.decoder.bind(response).join())
        as Map<String, dynamic>;
    if (response.statusCode >= 400) {
      final errors = decoded['errors'];
      String? firstError;
      if (errors is Map && errors.isNotEmpty) {
        final value = errors.values.first;
        if (value is List && value.isNotEmpty) firstError = '${value.first}';
      }
      throw ApiException(
          firstError ?? '${decoded['message'] ?? 'تعذر إكمال الطلب.'}',
          response.statusCode,
          errors: errors is Map ? Map<String, dynamic>.from(errors) : const {});
    }
    return decoded;
  }

  Future<void> logout() async {
    await _request('POST', '/api/mobile/logout');
    token = null;
  }

  Future<Map<String, dynamic>> _request(String method, String path,
      {Map<String, String>? query, Map<String, dynamic>? body}) async {
    if (apiBaseUrl.isEmpty) {
      throw const ApiException(
          'لم يُضبط عنوان الخادم في هذا الإصدار من التطبيق (API_BASE_URL).', 0);
    }
    if (const bool.fromEnvironment('dart.vm.product')) {
      final server = Uri.tryParse(apiBaseUrl);
      if (server == null ||
          server.scheme != 'https' ||
          server.host.isEmpty ||
          server.userInfo.isNotEmpty) {
        throw const ApiException(
            'يجب أن يستخدم خادم التطبيق اتصال HTTPS آمنًا.', 0);
      }
    }
    final uri =
        Uri.parse(apiBaseUrl).resolve(path).replace(queryParameters: query);
    final requestToken = token;
    try {
      final request = await _client
          .openUrl(method, uri)
          .timeout(const Duration(seconds: 12));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      if (requestToken != null)
        request.headers
            .set(HttpHeaders.authorizationHeader, 'Bearer $requestToken');
      final encodedBody = body == null ? null : utf8.encode(jsonEncode(body));
      // The production Apache/PHP host misroutes chunked POSTs as GETs.
      // Send the UTF-8 byte length for login, payments and readings alike.
      request.contentLength = encodedBody?.length ?? 0;
      if (encodedBody != null) {
        request.headers.contentType = ContentType.json;
        request.add(encodedBody);
      }
      final response =
          await request.close().timeout(const Duration(seconds: 18));
      return await _decodeResponse(response, requestToken)
          .timeout(const Duration(seconds: 18));
    } on SocketException {
      throw const ApiException(
          'لا يوجد اتصال بالإنترنت. تحقّق من الاتصال وحاول مجددًا.', 0);
    } on TimeoutException {
      throw const ApiException('انتهت مهلة الاتصال بالخادم.', 0);
    } on HandshakeException {
      throw const ApiException('تعذر إنشاء اتصال آمن بالخادم.', 0);
    } on HttpException {
      throw const ApiException('استجابة غير صالحة من الخادم.', 0);
    } on FormatException {
      throw const ApiException('استجابة غير مفهومة من الخادم.', 0);
    }
  }
}
