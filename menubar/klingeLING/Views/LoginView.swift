import SwiftUI

struct LoginView: View {
    @Environment(AppState.self) private var appState
    @State private var vm = LoginViewModel()

    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: "clock.fill")
                .font(.system(size: 28))
                .foregroundStyle(.tint)

            Text("Kachink")
                .font(.title3.bold())

            VStack(alignment: .leading, spacing: 8) {
                TextField("Server, e.g. time.example.com", text: $vm.serverURL)
                    .textFieldStyle(.roundedBorder)
                TextField("Email", text: $vm.email)
                    .textFieldStyle(.roundedBorder)
                    .textContentType(.username)
                SecureField("Password", text: $vm.password)
                    .textFieldStyle(.roundedBorder)
                    .textContentType(.password)
                    .onSubmit { if vm.canSubmit { Task { await vm.login(appState: appState) } } }
            }

            if let error = vm.error {
                Text(error)
                    .font(.caption)
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.center)
            }

            Button {
                Task { await vm.login(appState: appState) }
            } label: {
                if vm.isLoading {
                    ProgressView().controlSize(.small).frame(maxWidth: .infinity)
                } else {
                    Text("Sign in").frame(maxWidth: .infinity)
                }
            }
            .buttonStyle(.borderedProminent)
            .disabled(!vm.canSubmit)

            HStack {
                Spacer()
                Button("Quit") { NSApplication.shared.terminate(nil) }
                    .buttonStyle(.plain)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(20)
    }
}
