const MINUTE = 60_000;
const DAY = 24 * 60 * MINUTE;

export function entryDaysFrom(readingDay) {
    return Array.from({ length: 7 }, (_, offset) => (readingDay + offset) % 7);
}

export function shiftEntryDays(openDays, previousReadingDay, readingDay) {
    return openDays.map((day) => (day - previousReadingDay + readingDay + 7) % 7).sort((a, b) => a - b);
}

export function businessClock(now, timezone) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(now).map(({ type, value }) => [type, value]));
    const date = `${parts.year}-${parts.month}-${parts.day}`;

    return { date, time: `${parts.hour}:${parts.minute}`, weekday: new Date(`${date}T00:00:00Z`).getUTCDay() };
}

export function validEntryHours(schedule) {
    return /^([01]\d|2[0-3]):[0-5]\d$/.test(schedule.opens_at) && /^([01]\d|2[0-3]):[0-5]\d$/.test(schedule.closes_at)
        && schedule.opens_at < schedule.closes_at;
}

export function isEntryOpen(schedule, clock) {
    if (schedule.mode !== 'automatic') {
        return schedule.mode === 'open';
    }

    return validEntryHours(schedule) && schedule.open_days.includes(clock.weekday)
        && clock.time >= schedule.opens_at && clock.time <= schedule.closes_at;
}

export function weekDates({ start, end }) {
    const dates = [];
    for (let timestamp = Date.parse(`${start}T00:00:00Z`); timestamp <= Date.parse(`${end}T00:00:00Z`); timestamp += DAY) {
        const date = new Date(timestamp);
        dates.push({ date: date.toISOString().slice(0, 10), weekday: date.getUTCDay() });
    }

    return dates;
}

export function upcomingWeeks(first, count = 5) {
    const weeks = [first];
    for (let index = 1; index < count; index++) {
        const start = Date.parse(`${weeks[index - 1].end}T00:00:00Z`) + DAY;
        weeks.push({ start: new Date(start).toISOString().slice(0, 10), end: new Date(start + 6 * DAY).toISOString().slice(0, 10) });
    }

    return weeks;
}

export function nextScheduleChange(schedule, clock) {
    if (schedule.mode !== 'automatic' || !validEntryHours(schedule) || !schedule.open_days.length) {
        return null;
    }

    const minutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3));
    const today = Date.parse(`${clock.date}T00:00:00Z`);
    const current = today + minutes(clock.time) * MINUTE;
    const openNow = isEntryOpen(schedule, clock);
    const candidates = [];

    for (let offset = 0; offset <= 8; offset++) {
        const day = today + offset * DAY;
        candidates.push(day, day + minutes(schedule.opens_at) * MINUTE, day + (minutes(schedule.closes_at) + 1) * MINUTE);
    }

    for (const timestamp of [...new Set(candidates)].sort((a, b) => a - b)) {
        if (timestamp <= current) {
            continue;
        }

        const date = new Date(timestamp);
        const candidate = { date: date.toISOString().slice(0, 10), time: date.toISOString().slice(11, 16), weekday: date.getUTCDay() };
        const open = isEntryOpen(schedule, candidate);
        if (open !== openNow) {
            return { ...candidate, open };
        }
    }

    return null;
}
