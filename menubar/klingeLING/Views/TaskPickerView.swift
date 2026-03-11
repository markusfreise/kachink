import SwiftUI

struct TaskPickerView: View {
    let tasks: [TaskDTO]
    @Binding var selection: TaskDTO?
    @State private var search = ""
    @Environment(\.dismiss) private var dismiss

    private var filtered: [TaskDTO] {
        if search.isEmpty { return tasks }
        let q = search.lowercased()
        return tasks.filter { $0.name.lowercased().contains(q) }
    }

    var body: some View {
        VStack(spacing: 0) {
            TextField("Search tasks...", text: $search)
                .textFieldStyle(.roundedBorder)
                .padding(8)

            List {
                // "None" option
                Button {
                    selection = nil
                    dismiss()
                } label: {
                    HStack {
                        Text("None")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        if selection == nil {
                            Image(systemName: "checkmark")
                                .font(.caption)
                                .foregroundStyle(.blue)
                        }
                    }
                }
                .buttonStyle(.plain)

                ForEach(filtered) { task in
                    Button {
                        selection = task
                        dismiss()
                    } label: {
                        HStack {
                            Text(task.name)
                                .font(.caption)
                            Spacer()
                            if selection?.id == task.id {
                                Image(systemName: "checkmark")
                                    .font(.caption)
                                    .foregroundStyle(.blue)
                            }
                        }
                    }
                    .buttonStyle(.plain)
                }
            }
            .listStyle(.plain)
        }
    }
}
