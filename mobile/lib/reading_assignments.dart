/// The one reading round shown on the reader's screen: today's, then the
/// nearest published future round, otherwise the most recent uploadable one.
Map<String, dynamic> assignedReadingRoster(Map<String, dynamic> response) {
  final assignments = (response['assignments'] as List? ?? [])
      .map((item) => Map<String, dynamic>.from(item as Map))
      .toList();
  final today = '${response['business_date']}';
  final dates = assignments
      .map((item) => '${item['cycle']['reading_date']}')
      .toSet()
      .toList()
    ..sort();
  final next = response['next_reading'] as Map?;
  final date = next?['reading_date'] ??
      dates.where((date) => date.compareTo(today) >= 0).firstOrNull ??
      dates.lastOrNull;
  final work = assignments
      .where((item) => item['cycle']['reading_date'] == date)
      .toList();
  final cycle = work.isEmpty
      ? <String, dynamic>{}
      : Map<String, dynamic>.from(work.first['cycle'] as Map);
  final subscribers = <int, Map<String, dynamic>>{};
  for (final assignment in work) {
    final box = Map<String, dynamic>.from(assignment['meter_box'] as Map);
    for (final item in assignment['subscriptions'] as List) {
      final subscriber = Map<String, dynamic>.from(item as Map);
      subscribers[subscriber['id'] as int] = {
        ...subscriber,
        'full_name': subscriber['name'],
        'meter_box_id': box['id'],
        'meter_box_name': box['name'],
        'meter_box_number': box['number'],
        'meter_box_location': box['location'],
        'sub_area_id': box['sub_area_id'],
        'sub_area_name': box['sub_area_name'],
        'assignment_id': assignment['id'],
        'assignment_version': assignment['version'],
        'authorization': assignment['authorization'],
      };
    }
  }
  return {
    'assignment_roster_version': 1,
    'next_reading': next,
    'week_start': cycle['week_start'],
    'week_end': cycle['week_end'],
    'reading_date': cycle['reading_date'],
    'entry_starts_at': cycle['entry_starts_at'],
    'entry_ends_at': cycle['entry_ends_at'],
    'can_record_readings_now': cycle['entry_open'] == true,
    'data': subscribers.values.toList(),
    'current_page': 1,
    'last_page': 1,
  };
}

String readingDayLabel(String value) {
  final date = DateTime.parse(value);
  const days = [
    'الاثنين',
    'الثلاثاء',
    'الأربعاء',
    'الخميس',
    'الجمعة',
    'السبت',
    'الأحد'
  ];
  return '${days[date.weekday - 1]} ${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}';
}

String? readingScheduleMessage(Map<String, dynamic> state, DateTime now) {
  final date = state['next_reading']?['reading_date'] ?? state['reading_date'];
  if (date == null) return null;
  final label = readingDayLabel('$date');
  return readingEntryIsOpen(state, now)
      ? 'إدخال القراءات مفتوح اليوم: $label.'
      : 'موعد إدخال القراءات: $label. الإدخال متاح يوم القراءة فقط.';
}

/// Published offline work opens and closes on the server's dated window.
bool readingEntryIsOpen(Map<String, dynamic> state, DateTime now) {
  final starts = DateTime.tryParse('${state['entry_starts_at'] ?? ''}');
  final ends = DateTime.tryParse('${state['entry_ends_at'] ?? ''}');
  if (starts != null && ends != null)
    return !now.isBefore(starts) && !now.isAfter(ends);
  return state['can_record_readings_now'] == true;
}
