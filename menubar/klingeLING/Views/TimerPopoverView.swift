import SwiftUI

struct TimerPopoverView: View {
    @Environment(AppState.self) private var appState
    @State private var recentEntries: [TimeEntryDTO] = []
    @State private var mode: Mode = .timer

    private enum Mode { case timer, projectPicker, taskPicker }

    private var vm: TimerViewModel { appState.timerVM }

    var body: some View {
        Group {
            switch mode {
            case .timer:
                timerContent
            case .projectPicker:
                PickerListView(
                    title: "Project",
                    items: appState.projects,
                    allowNone: false,
                    label: { $0.name },
                    group: { $0.client?.name },
                    color: { $0.color },
                    isSelected: { $0.id == vm.selectedProject?.id },
                    onSelect: { project in
                        vm.selectedProject = project
                        mode = .timer
                    },
                    onCancel: { mode = .timer }
                )
            case .taskPicker:
                PickerListView(
                    title: "Task",
                    items: appState.tasks,
                    allowNone: true,
                    label: { $0.name },
                    group: { _ in nil },
                    color: { _ in nil },
                    isSelected: { $0.id == vm.selectedTask?.id },
                    onSelect: { task in
                        vm.selectedTask = task
                        mode = .timer
                    },
                    onCancel: { mode = .timer }
                )
            }
        }
        .task { await refresh() }
    }

    private var timerContent: some View {
        VStack(spacing: 0) {
            timerSection
            Divider()
            controlsSection
            Divider()
            recentSection
            Divider()
            footerSection
        }
    }

    // MARK: - Timer

    private var timerSection: some View {
        VStack(spacing: 4) {
            HStack(spacing: 10) {
                StatusDotView(isRunning: vm.isRunning)
                Text(vm.elapsedFormatted)
                    .font(.system(size: 34, weight: .medium, design: .rounded))
                    .monospacedDigit()
                    .foregroundStyle(vm.isRunning ? .primary : .secondary)
            }

            if vm.isRunning, let entry = vm.runningEntry {
                HStack(spacing: 6) {
                    if let color = entry.project?.color {
                        Circle().fill(Color(hex: color)).frame(width: 8, height: 8)
                    }
                    Text(entry.project?.name ?? "Project")
                        .font(.headline)
                    if let client = entry.project?.client?.name {
                        Text(client).font(.subheadline).foregroundStyle(.secondary)
                    }
                }
                if let task = entry.task?.name {
                    Text(task).font(.caption).foregroundStyle(.secondary)
                }
            } else {
                Text("No timer running")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.vertical, 14)
        .padding(.horizontal, 16)
        .frame(maxWidth: .infinity)
    }

    // MARK: - Controls

    private var controlsSection: some View {
        VStack(spacing: 8) {
            selectorButton(
                icon: nil,
                colorHex: vm.selectedProject?.color,
                title: vm.selectedProject?.name,
                subtitle: vm.selectedProject?.client?.name,
                placeholder: "Select project"
            ) { mode = .projectPicker }

            selectorButton(
                icon: "tag",
                colorHex: nil,
                title: vm.selectedTask?.name,
                subtitle: nil,
                placeholder: "Task (optional)"
            ) { mode = .taskPicker }
            .disabled(appState.tasks.isEmpty)

            TextField("What are you working on?", text: Bindable(vm).entryDescription)
                .textFieldStyle(.roundedBorder)
                .onSubmit {
                    Task {
                        if vm.isRunning { await vm.saveDescription() } else { await vm.start() }
                    }
                }

            HStack {
                Toggle("Billable", isOn: Bindable(vm).isBillable)
                    .toggleStyle(.checkbox)
                    .font(.caption)
                    .disabled(vm.isRunning)
                Spacer()
                Text("Cmd+Shift+T")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }

            HStack(spacing: 8) {
                if vm.isRunning {
                    Button {
                        Task { await vm.stop(); await refresh() }
                    } label: {
                        Label("Stop", systemImage: "stop.fill").frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.red)

                    if vm.canSwitch {
                        Button {
                            Task { await vm.switchTimer(); await refresh() }
                        } label: {
                            Label("Switch", systemImage: "arrow.triangle.2.circlepath").frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                    }
                } else {
                    Button {
                        Task { await vm.start() }
                    } label: {
                        Label("Start", systemImage: "play.fill").frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.green)
                    .disabled(vm.selectedProject == nil)
                }
            }
            .disabled(vm.isBusy)

            if let error = vm.error ?? appState.lastSyncError {
                Text(error)
                    .font(.caption2)
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.leading)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(12)
    }

    private func selectorButton(icon: String?, colorHex: String?, title: String?, subtitle: String?, placeholder: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 8) {
                if let colorHex {
                    Circle().fill(Color(hex: colorHex)).frame(width: 8, height: 8)
                } else if let icon {
                    Image(systemName: icon).font(.caption).foregroundStyle(.secondary)
                }
                if let title {
                    Text(title).lineLimit(1)
                    if let subtitle {
                        Text(subtitle).font(.caption).foregroundStyle(.secondary).lineLimit(1)
                    }
                } else {
                    Text(placeholder).foregroundStyle(.secondary)
                }
                Spacer()
                Image(systemName: "chevron.down").font(.caption).foregroundStyle(.secondary)
            }
            .padding(8)
            .background(.quaternary.opacity(0.5))
            .clipShape(RoundedRectangle(cornerRadius: 6))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    // MARK: - Recent

    private var recentSection: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text("Recent")
                .font(.caption.bold())
                .foregroundStyle(.secondary)
                .padding(.horizontal, 12)
                .padding(.top, 8)
                .padding(.bottom, 2)

            if recentEntries.isEmpty {
                Text("No recent entries")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .padding(.horizontal, 12)
                    .padding(.bottom, 8)
            } else {
                ForEach(recentEntries.prefix(5)) { entry in
                    RecentEntryRow(entry: entry) {
                        Task { await vm.restart(from: entry); await refresh() }
                    }
                }
                .padding(.bottom, 6)
            }
        }
    }

    // MARK: - Footer

    private var footerSection: some View {
        HStack {
            SettingsLink {
                Image(systemName: "gearshape")
            }
            .buttonStyle(.plain)

            Spacer()

            if let user = appState.currentUser {
                Text(user.name)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }

            Spacer()

            Button("Quit") { NSApplication.shared.terminate(nil) }
                .buttonStyle(.plain)
                .font(.caption)
        }
        .padding(12)
    }

    private func refresh() async {
        await vm.fetchRunning()
        do {
            recentEntries = try await appState.timerService.fetchRecent()
        } catch {
            // recent list is non-critical
        }
    }
}

struct RecentEntryRow: View {
    let entry: TimeEntryDTO
    let onRestart: () -> Void

    var body: some View {
        HStack(spacing: 8) {
            Circle()
                .fill(Color(hex: entry.project?.color ?? "#9CA3AF"))
                .frame(width: 6, height: 6)
            VStack(alignment: .leading, spacing: 1) {
                Text(entry.project?.name ?? "Unknown project")
                    .font(.caption)
                    .lineLimit(1)
                let detail = [entry.task?.name, entry.description].compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " - ")
                if !detail.isEmpty {
                    Text(detail)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer()
            Text(entry.durationHuman ?? "")
                .font(.caption.monospacedDigit())
                .foregroundStyle(.secondary)
            Button(action: onRestart) {
                Image(systemName: "play.circle.fill")
                    .foregroundStyle(.green)
            }
            .buttonStyle(.plain)
            .help("Start a new timer with this project and task")
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 3)
    }
}
