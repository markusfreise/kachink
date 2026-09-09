import AppKit
import CoreGraphics
import Foundation

/// Watches for user inactivity, system sleep and screen lock while a timer is
/// running and reports idle periods once the user is back.
@MainActor
final class IdleMonitor {
    struct IdlePeriod {
        let start: Date
        let end: Date
        var duration: TimeInterval { end.timeIntervalSince(start) }
    }

    var thresholdSeconds: TimeInterval { TimeInterval(Preferences.idleThresholdMinutes * 60) }

    /// Returns true when a timer is running, so idle tracking is relevant.
    var isTrackingActive: () -> Bool = { false }

    /// Called on return from an idle period that exceeded the threshold.
    var onIdleEnded: (IdlePeriod) -> Void = { _ in }

    private var pollTimer: Timer?
    private var idleStart: Date?
    private var sleepStart: Date?
    private var observers: [NSObjectProtocol] = []
    private var distributedObservers: [NSObjectProtocol] = []

    func start() {
        stop()
        pollTimer = Timer.scheduledTimer(withTimeInterval: 10, repeats: true) { [weak self] _ in
            Task { @MainActor in self?.poll() }
        }
        pollTimer?.tolerance = 2

        let wc = NSWorkspace.shared.notificationCenter
        observers.append(wc.addObserver(forName: NSWorkspace.willSleepNotification, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemWentAway() }
        })
        observers.append(wc.addObserver(forName: NSWorkspace.didWakeNotification, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemCameBack() }
        })
        observers.append(wc.addObserver(forName: NSWorkspace.screensDidSleepNotification, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemWentAway() }
        })
        observers.append(wc.addObserver(forName: NSWorkspace.screensDidWakeNotification, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemCameBack() }
        })

        let dc = DistributedNotificationCenter.default()
        distributedObservers.append(dc.addObserver(forName: Notification.Name("com.apple.screenIsLocked"), object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemWentAway() }
        })
        distributedObservers.append(dc.addObserver(forName: Notification.Name("com.apple.screenIsUnlocked"), object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.systemCameBack() }
        })
    }

    func stop() {
        pollTimer?.invalidate()
        pollTimer = nil
        observers.forEach { NSWorkspace.shared.notificationCenter.removeObserver($0) }
        observers.removeAll()
        distributedObservers.forEach { DistributedNotificationCenter.default().removeObserver($0) }
        distributedObservers.removeAll()
        idleStart = nil
        sleepStart = nil
    }

    /// Seconds since the last keyboard/mouse event in the current session.
    static func secondsSinceLastInput() -> TimeInterval {
        let anyEvent = CGEventType(rawValue: ~0) ?? .null
        return CGEventSource.secondsSinceLastEventType(.combinedSessionState, eventType: anyEvent)
    }

    private func poll() {
        guard isTrackingActive() else {
            idleStart = nil
            return
        }

        let idle = Self.secondsSinceLastInput()

        if idle >= thresholdSeconds {
            if idleStart == nil {
                idleStart = Date().addingTimeInterval(-idle)
            }
            return
        }

        // User is active again after an idle period.
        if let start = idleStart {
            idleStart = nil
            let end = Date().addingTimeInterval(-idle)
            let period = IdlePeriod(start: start, end: end)
            if period.duration >= thresholdSeconds {
                onIdleEnded(period)
            }
        }
    }

    private func systemWentAway() {
        guard isTrackingActive() else { return }
        if sleepStart == nil {
            // If the user was already idle before sleeping, count from then.
            sleepStart = idleStart ?? Date()
        }
    }

    private func systemCameBack() {
        guard let start = sleepStart else { return }
        sleepStart = nil
        idleStart = nil
        guard isTrackingActive() else { return }
        let period = IdlePeriod(start: start, end: Date())
        if period.duration >= thresholdSeconds {
            onIdleEnded(period)
        }
    }
}
