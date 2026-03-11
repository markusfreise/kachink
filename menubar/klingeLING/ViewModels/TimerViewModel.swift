import Foundation
import Observation

@MainActor
@Observable
final class TimerViewModel {
    var runningEntry: TimeEntryDTO?
    var elapsed: TimeInterval = 0
    var isRunning: Bool { runningEntry != nil }
    var error: String?

    // Selection state for new timers
    var selectedProject: ProjectDTO?
    var selectedTask: TaskDTO?
    var entryDescription: String = ""
    var isBillable: Bool = true

    let timerService: TimerService
    private var tickTask: Task<Void, Never>?

    var elapsedFormatted: String {
        let total = Int(elapsed)
        let h = total / 3600
        let m = (total % 3600) / 60
        let s = total % 60
        return String(format: "%d:%02d:%02d", h, m, s)
    }

    var shortElapsed: String {
        let total = Int(elapsed)
        let h = total / 3600
        let m = (total % 3600) / 60
        return String(format: "%d:%02d", h, m)
    }

    var currentProjectName: String {
        runningEntry?.project?.name ?? selectedProject?.name ?? "No project"
    }

    var currentClientName: String {
        runningEntry?.project?.client?.name ?? selectedProject?.client?.name ?? ""
    }

    init(timerService: TimerService) {
        self.timerService = timerService
    }

    func fetchRunning() async {
        do {
            let entry = try await timerService.fetchRunning()
            await MainActor.run {
                self.runningEntry = entry
                if entry != nil {
                    self.startTicking()
                }
            }
        } catch {
            // No running timer or network error
        }
    }

    func start() async {
        guard let project = selectedProject else {
            await MainActor.run { self.error = "Select a project first" }
            return
        }

        do {
            let request = StartTimerRequest(
                projectId: project.id,
                taskId: selectedTask?.id,
                description: entryDescription.isEmpty ? nil : entryDescription,
                isBillable: isBillable
            )
            let entry = try await timerService.startTimer(request: request)
            await MainActor.run {
                self.runningEntry = entry
                self.error = nil
                self.startTicking()
            }
        } catch {
            await MainActor.run { self.error = error.localizedDescription }
        }
    }

    func stop() async {
        do {
            _ = try await timerService.stopTimer()
            await MainActor.run {
                self.stopTicking()
                self.runningEntry = nil
            }
        } catch {
            await MainActor.run { self.error = error.localizedDescription }
        }
    }

    func quickRestart(from entry: TimeEntryDTO) async {
        // Stop current if running
        if isRunning {
            await stop()
        }
        // Start with same project/task
        do {
            let request = StartTimerRequest(
                projectId: entry.projectId,
                taskId: entry.taskId,
                description: entry.description,
                isBillable: entry.isBillable
            )
            let newEntry = try await timerService.startTimer(request: request)
            await MainActor.run {
                self.runningEntry = newEntry
                self.error = nil
                self.startTicking()
            }
        } catch {
            await MainActor.run { self.error = error.localizedDescription }
        }
    }

    func reset() {
        stopTicking()
        runningEntry = nil
        selectedProject = nil
        selectedTask = nil
        entryDescription = ""
        isBillable = true
    }

    private func startTicking() {
        stopTicking()
        guard let entry = runningEntry else { return }

        // Parse ISO 8601 date
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let startedAt = formatter.date(from: entry.startedAt)
            ?? ISO8601DateFormatter().date(from: entry.startedAt)
            ?? Date()

        elapsed = Date().timeIntervalSince(startedAt)

        tickTask = Task {
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(1))
                guard !Task.isCancelled else { return }
                self.elapsed += 1
            }
        }
    }

    private func stopTicking() {
        tickTask?.cancel()
        tickTask = nil
        elapsed = 0
    }
}
