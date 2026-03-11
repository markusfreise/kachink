import Foundation
import Observation

enum MenuBarState {
    case idle
    case tracking
    case offline
}

@MainActor
@Observable
final class AppState {
    var isAuthenticated = false
    var currentUser: UserDTO?
    var isOnline = true
    var projects: [ProjectDTO] = []
    var tasks: [TaskDTO] = []

    let apiClient = APIClient()
    let authService: AuthService
    let timerService: TimerService
    let projectService: ProjectService
    let syncService: SyncService
    let timerVM: TimerViewModel

    var menuBarState: MenuBarState {
        if !isOnline { return .offline }
        if timerVM.isRunning { return .tracking }
        return .idle
    }

    init() {
        self.authService = AuthService(apiClient: apiClient)
        self.timerService = TimerService(apiClient: apiClient)
        self.projectService = ProjectService(apiClient: apiClient)
        self.syncService = SyncService(apiClient: apiClient)
        self.timerVM = TimerViewModel(timerService: TimerService(apiClient: apiClient))

        if let token = KeychainHelper.read(service: "klingeling", account: "api-token") {
            apiClient.token = token
            Task { await validateAndLoad() }
        }
    }

    func validateAndLoad() async {
        do {
            let user = try await authService.validateToken()
            currentUser = user
            isAuthenticated = true

            async let p: () = loadProjects()
            async let t: () = loadTasks()
            async let r: () = timerVM.fetchRunning()
            _ = await (p, t, r)

            syncService.startMonitoring { [weak self] online in
                Task { @MainActor in self?.isOnline = online }
            }
        } catch {
            isAuthenticated = false
            currentUser = nil
            KeychainHelper.delete(service: "klingeling", account: "api-token")
            apiClient.token = nil
        }
    }

    func login(token: String) async throws {
        apiClient.token = token
        KeychainHelper.save(token, service: "klingeling", account: "api-token")
        await validateAndLoad()
        if !isAuthenticated {
            throw AuthError.invalidToken
        }
    }

    func logout() {
        KeychainHelper.delete(service: "klingeling", account: "api-token")
        apiClient.token = nil
        isAuthenticated = false
        currentUser = nil
        projects = []
        tasks = []
        timerVM.reset()
    }

    private func loadProjects() async {
        do {
            let result: APIResponse<[ProjectDTO]> = try await apiClient.get("/projects?filter[is_active]=true&per_page=200")
            projects = result.data
        } catch { /* silently fail */ }
    }

    private func loadTasks() async {
        do {
            let result: APIResponse<[TaskDTO]> = try await apiClient.get("/tasks")
            tasks = result.data
        } catch { /* silently fail */ }
    }
}

enum AuthError: LocalizedError {
    case invalidToken
    var errorDescription: String? {
        switch self {
        case .invalidToken: return "Invalid or expired token."
        }
    }
}
