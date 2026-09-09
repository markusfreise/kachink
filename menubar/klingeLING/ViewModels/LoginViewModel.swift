import Foundation
import Observation

@MainActor
@Observable
final class LoginViewModel {
    var serverURL: String = Preferences.serverURL
    var email: String = ""
    var password: String = ""
    var isLoading = false
    var error: String?

    var canSubmit: Bool {
        !isLoading && !serverURL.trimmingCharacters(in: .whitespaces).isEmpty && !email.isEmpty && !password.isEmpty
    }

    func login(appState: AppState) async {
        guard let url = APIClient.normalize(serverURL) else {
            error = "Please enter the server address."
            return
        }
        isLoading = true
        error = nil
        do {
            try await appState.login(serverURL: url, email: email, password: password)
            password = ""
        } catch {
            self.error = error.localizedDescription
        }
        isLoading = false
    }
}
