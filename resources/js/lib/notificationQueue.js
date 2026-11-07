/** How long each kind of notification stays up. Errors stay longer, so there is time to read why. */
export const NOTIFICATION_DURATIONS = { success: 4000, warning: 5000, error: 7000 };

/** How long a closing notification plays its exit before it is removed. */
export const NOTIFICATION_EXIT_DURATION = 200;

/**
 * The notifications on screen and their timers. Each one closes itself
 * after its type's duration, its timer can be paused (while the pointer is
 * over it) and resumed, and a closing one is flagged `leaving` for a short
 * exit before it is removed.
 */
export function createNotificationQueue(onChange) {
    let notifications = [];
    let sequence = 0;
    const timers = new Map();
    const exitTimers = new Map();

    function publish() {
        onChange([...notifications]);
    }

    function startTimer(id, remaining) {
        timers.set(id, { remaining, startedAt: Date.now(), paused: false, timeout: setTimeout(() => dismiss(id), remaining) });
    }

    function dismiss(id) {
        const timer = timers.get(id);

        if (!timer) {
            return;
        }

        clearTimeout(timer.timeout);
        timers.delete(id);
        notifications = notifications.map((notification) => (notification.id === id ? { ...notification, leaving: true } : notification));
        publish();

        exitTimers.set(
            id,
            setTimeout(() => {
                exitTimers.delete(id);
                notifications = notifications.filter((notification) => notification.id !== id);
                publish();
            }, NOTIFICATION_EXIT_DURATION),
        );
    }

    return {
        push(message, type = 'success') {
            if (typeof message !== 'string' || !message.trim()) {
                return;
            }
            const id = ++sequence;
            const duration = NOTIFICATION_DURATIONS[type] ?? NOTIFICATION_DURATIONS.success;
            notifications = [...notifications, { id, message, type, duration, leaving: false }];
            startTimer(id, duration);
            publish();
        },
        dismiss,
        pause(id) {
            const timer = timers.get(id);

            if (!timer || timer.paused) {
                return;
            }

            clearTimeout(timer.timeout);
            timer.remaining -= Date.now() - timer.startedAt;
            timer.paused = true;
        },
        resume(id) {
            const timer = timers.get(id);

            if (timer?.paused) {
                startTimer(id, timer.remaining);
            }
        },
        dispose() {
            timers.forEach((timer) => clearTimeout(timer.timeout));
            exitTimers.forEach((timeout) => clearTimeout(timeout));
            timers.clear();
            exitTimers.clear();
            notifications = [];
        },
    };
}
