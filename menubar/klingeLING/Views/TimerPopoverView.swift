import SwiftUI

struct TimerPopoverView: View {
    @Environment(AppState.self) private var appState
    @State private var recentEntries: [TimeEntryDTO] = []
    @State private var showProjectPicker = false
    @State private var showTaskPicker = false

    private var vm: TimerViewModel { appState.timerVM }

    var body: some View {
        VStack(spacing: 0) {
            // Timer display
            timerSection
            Divider()

            // Controls
            controlsSection
            Divider()

            // Recent entries
            recentSection

            Divider()
            // Footer
            footerSection
        }
        .task { await loadRecent() }
    }

    // MARK: - Timer Section

    private var timerSection: some View {
        VStack(spacing: 4) {
            HStack {
                StatusDotView(isRunning: vm.isRunning)
                Text(vm.elapsedFormatted)
                    .font(.system(size: 32, weight: .medium, design: .monospaced))
                    .foregroundStyle(vm.isRunning ? .primary : .secondary)
            }

            if vm.isRunning {
                Text(vm.currentProjectName)
                    .font(.headline)
                    .foregroundStyle(.primary)

                if !vm.currentClientName.isEmpty {
                    Text(vm.currentClientName)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let desc = vm.runningEntry?.description, !desc.isEmpty {
                    Text(desc)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            } else {
                Text("No timer running")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 12)
        .padding(.horizontal, 16)
        .frame(maxWidth: .infinity)
    }

    // MARK: - Controls Section

    private var controlsSection: some View {
        VStack(spacing: 8) {
            if !vm.isRunning {
                // Project picker
                Button {
                    showProjectPicker.toggle()
                } label: {
                    HStack {
                        if let project = vm.selectedProject {
                            Circle()
                                .fill(Color(hex: project.color))
                                .frame(width: 8, height: 8)
                            Text(project.name)
                        } else {
                            Text("Select project...")
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        Image(systemName: "chevron.down")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding(8)
                    .background(.quaternary.opacity(0.5))
                    .clipShape(RoundedRectangle(cornerRadius: 6))
                }
                .buttonStyle(.plain)
                .popover(isPresented: $showProjectPicker) {
                    ProjectPickerView(
                        projects: appState.projects,
                        selection: Bindable(vm).selectedProject
                    )
                    .frame(width: 280, height: 300)
                }

                // Task picker
                Button {
                    showTaskPicker.toggle()
                } label: {
                    HStack {
                        if let task = vm.selectedTask {
                            Text(task.name)
                        } else {
                            Text("Task (optional)")
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        Image(systemName: "chevron.down")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding(8)
                    .background(.quaternary.opacity(0.5))
                    .clipShape(RoundedRectangle(cornerRadius: 6))
                }
                .buttonStyle(.plain)
                .popover(isPresented: $showTaskPicker) {
                    TaskPickerView(
                        tasks: appState.tasks,
                        selection: Bindable(vm).selectedTask
                    )
                    .frame(width: 280, height: 300)
                }

                // Description
                TextField("Description (optional)", text: Bindable(vm).entryDescription)
                    .textFieldStyle(.roundedBorder)
                    .font(.caption)

                // Billable toggle
                Toggle("Billable", isOn: Bindable(vm).isBillable)
                    .font(.caption)
            }

            // Start/Stop button
            Button {
                Task {
                    if vm.isRunning {
                        await vm.stop()
                        await loadRecent()
                    } else {
                        await vm.start()
                    }
                }
            } label: {
                HStack {
                    Image(systemName: vm.isRunning ? "stop.fill" : "play.fill")
                    Text(vm.isRunning ? "Stop" : "Start")
                }
                .frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .tint(vm.isRunning ? .red : .green)
            .disabled(!vm.isRunning && vm.selectedProject == nil)

            if let error = vm.error {
                Text(error)
                    .font(.caption2)
                    .foregroundStyle(.red)
            }
        }
        .padding(12)
    }

    // MARK: - Recent Section

    private var recentSection: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Recent")
                .font(.caption.bold())
                .foregroundStyle(.secondary)
                .padding(.horizontal, 12)
                .padding(.top, 8)

            if recentEntries.isEmpty {
                Text("No recent entries")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .padding(.horizontal, 12)
                    .padding(.bottom, 8)
            } else {
                ForEach(recentEntries) { entry in
                    RecentEntryRow(entry: entry) {
                        Task {
                            await vm.quickRestart(from: entry)
                        }
                    }
                }
                .padding(.bottom, 4)
            }
        }
    }

    // MARK: - Footer

    private var footerSection: some View {
        HStack {
            Button {
                NSApp.sendAction(Selector(("showSettingsWindow:")), to: nil, from: nil)
            } label: {
                Image(systemName: "gear")
            }
            .buttonStyle(.plain)

            Spacer()

            if let user = appState.currentUser {
                Text(user.name)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }

            Spacer()

            Button("Quit") {
                NSApplication.shared.terminate(nil)
            }
            .buttonStyle(.plain)
            .font(.caption)
        }
        .padding(12)
    }

    private func loadRecent() async {
        do {
            let entries = try await appState.timerVM.timerService.fetchRecent()
            await MainActor.run { recentEntries = entries }
        } catch { /* ignore */ }
    }
}

// MARK: - Recent Entry Row

struct RecentEntryRow: View {
    let entry: TimeEntryDTO
    let onRestart: () -> Void

    var body: some View {
        HStack {
            if let color = entry.project?.color {
                Circle()
                    .fill(Color(hex: color))
                    .frame(width: 6, height: 6)
            }
            VStack(alignment: .leading, spacing: 1) {
                Text(entry.project?.name ?? "Unknown")
                    .font(.caption)
                    .lineLimit(1)
                if let desc = entry.description, !desc.isEmpty {
                    Text(desc)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer()
            Text(entry.durationHuman ?? "")
                .font(.caption.monospacedDigit())
                .foregroundStyle(.secondary)
            Button {
                onRestart()
            } label: {
                Image(systemName: "play.circle.fill")
                    .foregroundStyle(.green)
            }
            .buttonStyle(.plain)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 2)
    }
}
