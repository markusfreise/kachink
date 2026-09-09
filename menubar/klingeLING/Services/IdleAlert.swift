import AppKit
import Foundation

enum IdleDecision {
    case keep            // Keep the idle time, timer keeps running
    case discardContinue // Remove the idle time and keep tracking from now
    case stop            // Stop the timer at the moment idle time began
}

@MainActor
enum IdleAlert {
    static func present(period: IdleMonitor.IdlePeriod, projectName: String) -> IdleDecision {
        let minutes = Int(period.duration / 60)
        let timeFormatter = DateFormatter()
        timeFormatter.timeStyle = .short
        timeFormatter.dateStyle = .none

        let alert = NSAlert()
        alert.messageText = "You were away for \(minutes) minutes"
        alert.informativeText = "The timer for \"\(projectName)\" kept running since \(timeFormatter.string(from: period.start)). What should happen with the idle time?"
        alert.alertStyle = .informational
        alert.addButton(withTitle: "Discard idle time and continue")
        alert.addButton(withTitle: "Stop timer at \(timeFormatter.string(from: period.start))")
        alert.addButton(withTitle: "Keep idle time")

        NSApp.activate(ignoringOtherApps: true)
        switch alert.runModal() {
        case .alertFirstButtonReturn: return .discardContinue
        case .alertSecondButtonReturn: return .stop
        default: return .keep
        }
    }
}
