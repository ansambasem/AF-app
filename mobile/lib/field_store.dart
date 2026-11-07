import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/services.dart';

String newOperationId() {
  final bytes = List<int>.generate(16, (_) => Random.secure().nextInt(256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  final hex =
      bytes.map((byte) => byte.toRadixString(16).padLeft(2, '0')).join();
  return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20)}';
}

class PendingReadingsAccountSwitch implements Exception {}

class FieldStore {
  FieldStore({Directory? directory}) : _directory = directory;
  Directory? _directory;
  Map<String, dynamic> state = {};
  Future<void> _lastWrite = Future<void>.value();
  Future<void> _lastMutation = Future<void>.value();
  static const _session = MethodChannel('powercollect.session');
  String? _persistedToken;

  Future<File> _file() async {
    _directory ??= Directory(await const MethodChannel('powercollect.storage')
            .invokeMethod<String>('filesDirectory') ??
        '');
    if (_directory!.path.isEmpty)
      throw StateError('Local storage is unavailable');
    await _directory!.create(recursive: true);
    return File('${_directory!.path}${Platform.pathSeparator}field_state.json');
  }

  Future<void> load() async {
    final file = await _file();
    if (!await file.exists()) return;
    final decoded = jsonDecode(await file.readAsString());
    if (decoded is Map<String, dynamic>) state = decoded;
    final legacyToken = token;
    if (legacyToken != null) {
      await _session.invokeMethod<void>('write', legacyToken);
      _persistedToken = legacyToken;
      state['remember_session'] ??= true;
      await save();
    } else if (state['secure_token'] == true) {
      state['token'] = await _session.invokeMethod<String>('read');
      _persistedToken = token;
    }
  }

  Future<void> save() {
    final nextToken = token;
    final persisted = Map<String, dynamic>.from(state)..remove('token');
    persisted['secure_token'] = nextToken != null;
    final snapshot = jsonEncode(persisted);
    final write = _lastWrite.then<void>((_) async {
      var changedToken = false;
      try {
        if (nextToken != _persistedToken) {
          await _session.invokeMethod<void>(
              nextToken == null ? 'clear' : 'write', nextToken);
          changedToken = true;
        }
        final file = await _file();
        final temporary = File('${file.path}.tmp');
        await temporary.writeAsString(snapshot, flush: true);
        await temporary.rename(file.path);
        _persistedToken = nextToken;
      } catch (_) {
        if (changedToken) {
          await _session.invokeMethod<void>(
              _persistedToken == null ? 'clear' : 'write', _persistedToken);
        }
        rethrow;
      }
    });
    _lastWrite =
        write.then<void>((_) {}, onError: (Object _, StackTrace __) {});

    return write;
  }

  Future<void> saveSession(Map<String, dynamic> nextUser, String nextToken,
          {bool remember = false}) =>
      _mutate(() {
        if (user?['id'] != nextUser['id']) {
          if (queuedReadings.isNotEmpty) {
            throw PendingReadingsAccountSwitch();
          }
          for (final key in [
            'subscribers',
            'week_start',
            'week_end',
            'can_record_readings_now',
            'roster_updated_at',
            'assignment_roster_version',
            'next_reading',
            'entry_starts_at',
            'entry_ends_at',
            'reading_date',
            'queued_readings',
            'reading_drafts',
            'reading_drafts_week',
          ]) {
            state.remove(key);
          }
        }
        state['user'] = nextUser;
        state['token'] = nextToken;
        state['remember_session'] = remember;
      });

  bool get remembersSession => state['remember_session'] != false;

  List<Map<String, dynamic>> get queuedReadings =>
      (state['queued_readings'] as List<dynamic>? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();

  List<Map<String, dynamic>> get subscribers =>
      (state['subscribers'] as List<dynamic>? ?? [])
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();

  Map<String, dynamic>? get user => state['user'] is Map
      ? Map<String, dynamic>.from(state['user'] as Map)
      : null;

  String? get token => state['token'] as String?;

  Future<void> queueReading(Map<String, dynamic> reading) =>
      queueReadings([reading]);

  Future<void> queueReadings(List<Map<String, dynamic>> readings) async {
    await _mutate(() {
      final queue = queuedReadings;
      queue.addAll(readings);
      state['queued_readings'] = queue;
      final drafts = readingDrafts;
      for (final reading in readings) {
        drafts.remove(reading['subscription_id']);
      }
      _putDrafts(drafts);
    });
  }

  /// Readings typed for this week's roster but not saved yet, by subscriber
  /// id, so leaving a box or closing the app loses none of them.
  Map<int, String> get readingDrafts {
    final drafts = state['reading_drafts'];
    if (drafts is! Map || state['reading_drafts_week'] != state['week_start'])
      return {};
    return {
      for (final entry in drafts.entries)
        if (int.tryParse('${entry.key}') != null)
          int.parse('${entry.key}'): '${entry.value}',
    };
  }

  Future<void> saveReadingDrafts(Map<int, String> drafts,
          {String? weekStart}) =>
      _mutate(() {
        if (weekStart != null && weekStart != state['week_start']) return;
        _putDrafts(drafts);
      });

  /// Take a reading that has not reached the server off the queue and put
  /// its number back as a draft, to be corrected and saved again.
  Future<void> reopenReading(String operationId) async {
    await _mutate(() {
      final queue = queuedReadings;
      final reading = queue
          .where((item) => item['mobile_operation_id'] == operationId)
          .firstOrNull;
      if (reading == null || reading['upload_uncertain'] == true) return;
      queue.remove(reading);
      state['queued_readings'] = queue;
      _putDrafts({
        ...readingDrafts,
        reading['subscription_id'] as int: '${reading['current_reading']}',
      });
    });
  }

  void _putDrafts(Map<int, String> drafts) {
    state['reading_drafts'] = {
      for (final entry in drafts.entries)
        if (entry.value.isNotEmpty) '${entry.key}': entry.value,
    };
    state['reading_drafts_week'] = state['week_start'];
  }

  Future<void> removeReading(String operationId) async {
    await _mutate(() {
      state['queued_readings'] = queuedReadings
          .where((reading) => reading['mobile_operation_id'] != operationId)
          .toList();
    });
  }

  Future<void> markReadingError(String operationId, String error) async {
    await _mutate(() {
      state['queued_readings'] = queuedReadings.map((reading) {
        if (reading['mobile_operation_id'] == operationId) {
          reading['sync_error'] = error;
          reading.remove('upload_uncertain');
        }
        return reading;
      }).toList();
    });
  }

  Future<void> markReadingUncertain(String operationId,
          {bool uncertain = true}) =>
      _mutate(() {
        state['queued_readings'] = queuedReadings.map((reading) {
          if (reading['mobile_operation_id'] == operationId) {
            if (uncertain) {
              reading['upload_uncertain'] = true;
            } else {
              reading.remove('upload_uncertain');
            }
          }
          return reading;
        }).toList();
      });

  Future<void> _mutate(void Function() change) {
    final mutation = _lastMutation.then<void>((_) async {
      final previous = jsonEncode(state);
      try {
        change();
        await save();
      } catch (_) {
        state = jsonDecode(previous) as Map<String, dynamic>;
        rethrow;
      }
    });
    _lastMutation =
        mutation.then<void>((_) {}, onError: (Object _, StackTrace __) {});

    return mutation;
  }
}
