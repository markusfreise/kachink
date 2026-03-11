import Foundation
import Observation

@MainActor
@Observable
final class LoginViewModel {
    var token: String = ""
    var serverURL: String = "http://localhost:8081"
    var isLoading = false
    var error: String?

    func login(appState: AppState) async {
        let trimmed = token.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            error = "Please enter your API token"
            return
        }

        isLoading = true
        error = nil

        if let url = URL(string: serverURL + "/api") {
            appState.apiClient.baseURL = url
        }

        do {
            try await appState.login(token: trimmed)
        } catch {
            self.error = error.localizedDescription
        }

        isLoading = false
    }
}
