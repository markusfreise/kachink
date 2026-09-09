import Foundation
import Observation

@MainActor
@Observable
final class TimerViewModel {
    var runningEntry: TimeEntryDTO?
    var elapsed: TimeInterval = 0
    var isRunning: Bool { runningEntry != nil }
    var error: String?
    var isBusy = false

    // Selection state for new timers
    var selectedProject: ProjectDTO?
    var selectedTask: TaskDTO?
    var entryDescription: String = ""
    var isBillable: Bool = true

    let timerService: TimerService
    private var tickTask: Task<Void, Never>?
    private var startedAt: Date?

    init(timerService: TimerService) {
        self.timerService = timerService
    }

    var elapsedFormatted: String {
        let total = Int(elapsed)
        return String(format: "%d:%02d:%02d", total / 3600, (total % 3600) / 60, total % 60)
    }

    var menuBarElapsed: String {
        let total = Int(elapsed)
        if Preferences.showSecondsInMenuBar {
            return String(format: "%d:%02d:%02d", total / 3600, (total % 3600) / 60, total % 60)
        }
        return String(format: "%d:%02d", total / 3600, (total % 3600) / 60)
    }

    var currentProjectName: String {
        runningEntry?.project?.name ?? selectedProject?.name ?? "No project"
    }

    var currentClientName: String {
        runningEntry?.project?.client?.name ?? selectedProject?.client?.name ?? ""
    }

    /// True when a different project/task is selected than the running one, i.e. a "switch" is possible.
    var canSwitch: Bool {
        guard let running = runningEntry, let project = selectedProject else { return false }
        return project.id != running.projectId || (selectedTask?.id ?? "") != (running.taskId ?? "")
    }

    // MARK: - Server sync

    func fetchRunning() async {
        do {
            let entry = try await timerService.fetchRunning()
            apply(entry)
            error = nil
        } catch APIError.unauthorized {
            // handled globally
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func apply(_ entry: TimeEntryDTO?) {
        if let entry {
            let changed = entry.id != runningEntry?.id
            runningEntry = entry
            if changed || tickTask == nil { startTicking() }
            if changed {
                selectedProject = entry.project ?? selectedProject
                selectedTask = entry.task
                entryDescription = entry.description ?? ""
                isBillable = entry.isBillable
            }
        } else {
            runningEntry = nil
            stopTicking()
        }
    }

    // MARK: - Actions

    func start() async {
        guard let project = selectedProject else {
            error = "Select a project first."
            return
        }
        await run {
            let request = StartTimerRequest(
                projectId: project.id,
                taskId: self.selectedTask?.id,
                description: self.entryDescription.isEmpty ? nil : self.entryDescription,
                isBillable: self.isBillable
            )
            let entry = try await self.timerService.startTimer(request: request)
            Preferences.lastProjectId = project.id
            Preferences.lastTaskId = self.selectedTask?.id
            self.apply(entry)
        }
    }

    func stop() async {
        await run {
            _ = try await self.timerService.stopTimer()
            self.apply(nil)
            self.entryDescription = ""
        }
    }

    /// Stops the current timer and starts a new one with the current selection.
    func switchTimer() async {
        await start() // the server stops any running entry first
    }

    func restart(from entry: TimeEntryDTO) async {
        await run {
            let request = StartTimerRequest(
                projectId: entry.projectId,
                taskId: entry.taskId,
                description: entry.description,
                isBillable: entry.isBillable
            )
            let started = try await self.timerService.startTimer(request: request)
            self.apply(started)
        }
    }

    /// Toggle used by the global hotkey: stop if running, otherwise restart the last selection.
    func toggle(projects: [ProjectDTO], tasks: [TaskDTO]) async {
        if isRunning {
            await stop()
            return
        }
        if selectedProject == nil, let lastId = Preferences.lastProjectId {
            selectedProject = projects.first { $0.id == lastId }
            selectedTask = tasks.first { $0.id == Preferences.lastTaskId }
        }
        await start()
    }

    func saveDescription() async {
        guard let entry = runningEntry else { return }
        let text = entryDescription
        guard text != (entry.description ?? "") else { return }
        await run {
            let updated = try await self.timerService.updateDescription(entryId: entry.id, description: text)
            self.runningEntry = updated
        }
    }

    // MARK: - Idle handling

    func resolveIdle(_ period: IdleMonitor.IdlePeriod, decision: IdleDecision) async {
        guard let entry = runningEntry else { return }
        switch decision {
        case .keep:
            return
        case .stop:
            await run {
                _ = try await self.timerService.stop(entryId: entry.id, at: period.start)
                self.apply(nil)
            }
        case .discardContinue:
            await run {
                _ = try await self.timerService.stop(entryId: entry.id, at: period.start)
                let request = StartTimerRequest(
                    projectId: entry.projectId,
                    taskId: entry.taskId,
                    description: entry.description,
                    isBillable: entry.isBillable
                )
                let restarted = try await self.timerService.startTimer(request: request)
                self.apply(restarted)
            }
        }
    }

    func reset() {
        stopTicking()
        runningEntry = nil
        selectedProject = nil
        selectedTask = nil
        entryDescription = ""
        isBillable = true
        error = nil
    }

    // MARK: - Ticking

    /// Re-syncs the elapsed time from the start timestamp (after sleep/wake).
    func resync() {
        if let startedAt { elapsed = max(0, Date().timeIntervalSince(startedAt)) }
    }

    private func startTicking() {
        stopTicking()
        guard let entry = runningEntry else { return }
        let start = entry.startedDate ?? Date()
        startedAt = start
        elapsed = max(0, Date().timeIntervalSince(start))

        tickTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(1))
                guard !Task.isCancelled, let self else { return }
                self.resync()
            }
        }
    }

    private func stopTicking() {
        tickTask?.cancel()
        tickTask = nil
        startedAt = nil
        elapsed = 0
    }

    private func run(_ work: @escaping () async throws -> Void) async {
        isBusy = true
        defer { isBusy = false }
        do {
            try await work()
            error = nil
        } catch APIError.unauthorized {
            // handled globally
        } catch {
            self.error = error.localizedDescription
        }
    }
}
