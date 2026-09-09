import AppKit
import Foundation
import Observation

enum MenuBarState {
    case idle
    case tracking
    case error
}

@MainActor
@Observable
final class AppState {
    var isAuthenticated = false
    var isRestoringSession = false
    var currentUser: UserDTO?
    var organizations: [OrganizationDTO] = []
    var currentOrganizationId: String?
    var projects: [ProjectDTO] = []
    var tasks: [TaskDTO] = []
    var lastSyncError: String?

    let apiClient = APIClient()
    let authService: AuthService
    let timerService: TimerService
    let timerVM: TimerViewModel
    let idleMonitor = IdleMonitor()
    private let hotkeys = HotkeyManager()

    private var refreshTask: Task<Void, Never>?
    private var wakeObserver: NSObjectProtocol?
    private var handlingIdle = false

    var menuBarState: MenuBarState {
        if timerVM.isRunning { return .tracking }
        if lastSyncError != nil && isAuthenticated { return .error }
        return .idle
    }

    var currentOrganization: OrganizationDTO? {
        organizations.first { $0.id == currentOrganizationId }
    }

    init() {
        authService = AuthService(apiClient: apiClient)
        timerService = TimerService(apiClient: apiClient)
        timerVM = TimerViewModel(timerService: timerService)

        apiClient.onUnauthorized = { [weak self] in self?.sessionExpired() }

        idleMonitor.isTrackingActive = { [weak self] in self?.timerVM.isRunning ?? false }
        idleMonitor.onIdleEnded = { [weak self] period in self?.handleIdle(period) }

        HotkeyManager.onToggle = { [weak self] in
            guard let self, self.isAuthenticated else { return }
            Task { await self.timerVM.toggle(projects: self.projects, tasks: self.tasks) }
        }

        if let token = KeychainHelper.read(service: "klingeling", account: "api-token"), apiClient.baseURL != nil {
            apiClient.token = token
            isRestoringSession = true
            Task { await restoreSession() }
        }
    }

    // MARK: - Session

    func login(serverURL: URL, email: String, password: String) async throws {
        apiClient.baseURL = serverURL
        apiClient.token = nil
        apiClient.organizationId = nil

        let response = try await authService.login(email: email, password: password)
        apiClient.token = response.token
        KeychainHelper.save(response.token, service: "klingeling", account: "api-token")
        Preferences.serverURL = serverURL.absoluteString.replacingOccurrences(of: "/api", with: "")
        currentUser = response.data

        try await loadOrganizations()
        isAuthenticated = true
        await loadWorkspace()
        startBackgroundWork()
    }

    private func restoreSession() async {
        defer { isRestoringSession = false }
        do {
            currentUser = try await authService.me()
            try await loadOrganizations()
            isAuthenticated = true
            await loadWorkspace()
            startBackgroundWork()
        } catch APIError.unauthorized {
            clearSession()
        } catch {
            // Server unreachable: keep the token, show the error, retry later.
            lastSyncError = error.localizedDescription
            isAuthenticated = true
            startBackgroundWork()
        }
    }

    private func loadOrganizations() async throws {
        organizations = try await authService.organizations()
        let stored = Preferences.organizationId
        if let stored, organizations.contains(where: { $0.id == stored }) {
            currentOrganizationId = stored
        } else {
            currentOrganizationId = organizations.first?.id
        }
        Preferences.organizationId = currentOrganizationId
        apiClient.organizationId = currentOrganizationId
    }

    func selectOrganization(_ id: String) {
        guard id != currentOrganizationId else { return }
        currentOrganizationId = id
        Preferences.organizationId = id
        apiClient.organizationId = id
        timerVM.reset()
        Task { await loadWorkspace() }
    }

    func logout() {
        let token = apiClient.token
        stopBackgroundWork()
        clearSession()
        if token != nil {
            Task { try? await authService.logout() }
        }
    }

    private func sessionExpired() {
        stopBackgroundWork()
        clearSession()
    }

    private func clearSession() {
        KeychainHelper.delete(service: "klingeling", account: "api-token")
        apiClient.token = nil
        isAuthenticated = false
        currentUser = nil
        organizations = []
        projects = []
        tasks = []
        lastSyncError = nil
        timerVM.reset()
    }

    // MARK: - Data

    func loadWorkspace() async {
        async let p = loadProjects()
        async let t = loadTasks()
        async let r: () = timerVM.fetchRunning()
        _ = await (p, t, r)
        if timerVM.selectedProject == nil, let last = Preferences.lastProjectId {
            timerVM.selectedProject = projects.first { $0.id == last }
            timerVM.selectedTask = tasks.first { $0.id == Preferences.lastTaskId }
        }
    }

    private func loadProjects() async {
        do {
            projects = try await timerService.fetchProjects()
            lastSyncError = nil
        } catch APIError.unauthorized {
        } catch {
            lastSyncError = error.localizedDescription
        }
    }

    private func loadTasks() async {
        do {
            tasks = try await timerService.fetchTasks()
        } catch APIError.unauthorized {
        } catch {
            lastSyncError = error.localizedDescription
        }
    }

    // MARK: - Background work

    private func startBackgroundWork() {
        stopBackgroundWork()
        idleMonitor.start()
        hotkeys.register()

        refreshTask = Task { [weak self] in
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(30))
                guard !Task.isCancelled, let self else { return }
                await self.timerVM.fetchRunning()
                if self.projects.isEmpty { await self.loadProjects() }
                if self.tasks.isEmpty { await self.loadTasks() }
                if self.timerVM.error == nil { self.lastSyncError = nil }
            }
        }

        wakeObserver = NSWorkspace.shared.notificationCenter.addObserver(
            forName: NSWorkspace.didWakeNotification, object: nil, queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                self?.timerVM.resync()
                await self?.timerVM.fetchRunning()
            }
        }
    }

    private func stopBackgroundWork() {
        refreshTask?.cancel()
        refreshTask = nil
        idleMonitor.stop()
        hotkeys.unregister()
        if let wakeObserver {
            NSWorkspace.shared.notificationCenter.removeObserver(wakeObserver)
            self.wakeObserver = nil
        }
    }

    // MARK: - Idle watchdog

    private func handleIdle(_ period: IdleMonitor.IdlePeriod) {
        guard !handlingIdle, timerVM.isRunning else { return }
        handlingIdle = true
        let decision = IdleAlert.present(period: period, projectName: timerVM.currentProjectName)
        Task {
            await timerVM.resolveIdle(period, decision: decision)
            handlingIdle = false
        }
    }
}
