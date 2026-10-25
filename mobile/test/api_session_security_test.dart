import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:power_collect/api_client.dart';

class SessionHeaders implements HttpHeaders {
  final values = <String, Object>{};
  @override
  void set(String name, Object value, {bool preserveHeaderCase = false}) =>
      values[name] = value;
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class SessionResponse extends Stream<List<int>> implements HttpClientResponse {
  SessionResponse(this.statusCode, this.body);
  @override
  final int statusCode;
  final String body;
  @override
  StreamSubscription<List<int>> listen(void Function(List<int>)? onData,
          {Function? onError, void Function()? onDone, bool? cancelOnError}) =>
      Stream.value(utf8.encode(body)).listen(onData,
          onError: onError, onDone: onDone, cancelOnError: cancelOnError);
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class SessionRequest implements HttpClientRequest {
  SessionRequest(this.response);
  final HttpClientResponse response;
  @override
  final SessionHeaders headers = SessionHeaders();
  @override
  bool followRedirects = true;
  String? body;
  @override
  void write(Object? value) => body = '$value';
  @override
  Future<HttpClientResponse> close() async => response;
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class SessionHttpClient implements HttpClient {
  SessionHttpClient(this.request);
  final SessionRequest request;
  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async => request;
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

void main() {
  test(
      'an unauthorized API response notifies the application to return to login',
      () async {
    final request =
        SessionRequest(SessionResponse(401, '{"message":"Unauthenticated."}'));
    var callbacks = 0;
    final api = ApiClient(httpClient: SessionHttpClient(request))
      ..token = 'old-session'
      ..onUnauthenticated = () => callbacks++;
    await expectLater(
        api.me(),
        throwsA(isA<ApiException>()
            .having((error) => error.statusCode, 'status', 401)));
    expect(callbacks, 1);
  });

  test(
      'a late unauthorized reply for an old token cannot log out a newer session',
      () async {
    final request =
        SessionRequest(SessionResponse(401, '{"message":"Unauthenticated."}'));
    var callbacks = 0;
    final api = ApiClient(httpClient: SessionHttpClient(request))
      ..token = 'old-session'
      ..onUnauthenticated = () => callbacks++;
    final pending = api.me();
    api.token = 'new-session';
    await expectLater(pending, throwsA(isA<ApiException>()));
    expect(callbacks, 0);
    expect(api.token, 'new-session');
    expect(request.headers.values[HttpHeaders.authorizationHeader],
        'Bearer old-session');
  });
  test(
      'login sends remember and the existing device token without following redirects',
      () async {
    final request =
        SessionRequest(SessionResponse(200, '{"token":"renewed","user":{}}'));
    final api = ApiClient(httpClient: SessionHttpClient(request))
      ..token = 'existing';
    await api.login('reader', 'password', remember: true);
    expect(jsonDecode(request.body!)['remember'], true);
    expect(request.headers.values[HttpHeaders.authorizationHeader],
        'Bearer existing');
    expect(request.followRedirects, false);
    expect(api.token, 'renewed');
  });

  test('API redirects are rejected without replacing the current token',
      () async {
    final request =
        SessionRequest(SessionResponse(302, '<html>Redirect</html>'));
    final api = ApiClient(httpClient: SessionHttpClient(request))
      ..token = 'existing';
    await expectLater(
        api.me(),
        throwsA(isA<ApiException>().having(
            (error) => error.message, 'message', contains('إعادة توجيه'))));
    expect(request.followRedirects, false);
    expect(api.token, 'existing');
  });
}
