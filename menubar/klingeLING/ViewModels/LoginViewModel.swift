import AppKit
import Foundation
import Observation

@MainActor
@Observable
final class LoginViewModel {
    var serverURL: String = Preferences.serverURL.isEmpty ? "kachink.croeso.de" : Preferences.serverURL
    var isWaiting = false
    var error: String?

    private var pollTask: Task<Void, Never>?

    var canSubmit: Bool {
        !isWaiting && !serverURL.trimmingCharacters(in: .whitespaces).isEmpty
    }

    /// Opens the web app, where the signed-in user approves this Mac; the token is
    /// then picked up by polling. No password is ever typed into the app.
    func signInWithBrowser(appState: AppState) {
        guard let apiURL = APIClient.normalize(serverURL) else {
            error = "Please enter the server address."
            return
        }
        error = nil
        isWaiting = true
        appState.apiClient.baseURL = apiURL
        appState.apiClient.token = nil

        let deviceName = "\(Host.current().localizedName ?? "Mac") (Kachink)"
        let webBase = apiURL.absoluteString.replacingOccurrences(of: "/api", with: "")

        pollTask?.cancel()
        pollTask = Task { [weak self, weak appState] in
            guard let self, let appState else { return }
            do {
                let start = try await appState.authService.startDeviceLogin(deviceName: deviceName)
                var components = URLComponents(string: webBase + "/connect")
                components?.queryItems = [URLQueryItem(name: "code", value: start.code)]
                if let url = components?.url { NSWorkspace.shared.open(url) }

                let deadline = Date().addingTimeInterval(TimeInterval(start.expiresIn))
                while !Task.isCancelled && Date() < deadline {
                    try await Task.sleep(for: .seconds(max(2, start.pollInterval)))
                    if let result = try await appState.authService.pollDeviceLogin(code: start.code) {
                        if result.status == "approved", let token = result.token {
                            await appState.completeDeviceLogin(serverURL: apiURL, token: token, organizationId: result.organizationId, user: result.user)
                            self.isWaiting = false
                            return
                        }
                    }
                }
                self.error = "The request timed out. Please try again."
            } catch APIError.httpError(let status, _) where status == 403 {
                self.error = "The request was denied in the browser."
            } catch is CancellationError {
                // cancelled by user
            } catch {
                self.error = error.localizedDescription
            }
            self.isWaiting = false
        }
    }

    func cancel() {
        pollTask?.cancel()
        pollTask = nil
        isWaiting = false
    }
}
