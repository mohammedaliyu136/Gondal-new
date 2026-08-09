import 'package:flutter/material.dart';

/// Who was actually reached on a visit.
///
/// WHY THIS EXISTS RATHER THAN A NUMBER FIELD. The server derives
/// `farmers_reached` by counting the farmer ids sent, and it filters falsy
/// values — so a typed count cannot be faked with placeholder ids, it would
/// save as zero. The households have to be named to be counted, which is also
/// the more useful record: "eleven households" tells an M&E officer nothing
/// they can follow up, and eleven names tells them everything.
///
/// It also feeds the single-farmer case the server treats specially: a visit
/// naming exactly one household fills `field_activities.farmer_id`, which is
/// what links a visit to a quality follow-up.
class FarmerPicker extends StatefulWidget {
  const FarmerPicker({
    super.key,
    required this.farmers,
    required this.selected,
    required this.onChanged,
  });

  /// Rows as the server sends them: `{id, name, code, phone, ...}`.
  final List<dynamic> farmers;
  final Set<int> selected;
  final ValueChanged<Set<int>> onChanged;

  @override
  State<FarmerPicker> createState() => _FarmerPickerState();
}

class _FarmerPickerState extends State<FarmerPicker> {
  final _search = TextEditingController();
  String _term = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<dynamic> get _visible {
    if (_term.isEmpty) return widget.farmers;

    final needle = _term.toLowerCase();

    return widget.farmers.where((f) {
      final row = f as Map<String, dynamic>;

      return [row['name'], row['code'], row['phone']]
          .any((v) => v != null && v.toString().toLowerCase().contains(needle));
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    if (widget.farmers.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 12),
        child: Text(
          'No farmers for this community on the phone yet. '
          'You can still save the visit without naming households.',
          style: TextStyle(fontSize: 13, color: scheme.outline),
        ),
      );
    }

    final visible = _visible;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'Households reached',
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            Text(
              '${widget.selected.length} selected',
              style: TextStyle(fontSize: 13, color: scheme.primary),
            ),
          ],
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _search,
          decoration: const InputDecoration(
            prefixIcon: Icon(Icons.search),
            labelText: 'Find a farmer',
            isDense: true,
          ),
          onChanged: (v) => setState(() => _term = v.trim()),
        ),
        const SizedBox(height: 8),
        // Bounded, because this list sits inside a scrolling form and an
        // unbounded one would fight the page for the gesture.
        ConstrainedBox(
          constraints: const BoxConstraints(maxHeight: 260),
          child: Material(
            color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
            borderRadius: BorderRadius.circular(8),
            child: visible.isEmpty
                ? const Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('Nobody matches that.'),
                  )
                : ListView.builder(
                    shrinkWrap: true,
                    itemCount: visible.length,
                    itemBuilder: (context, i) {
                      final row = visible[i] as Map<String, dynamic>;
                      final id = row['id'] as int;
                      final checked = widget.selected.contains(id);

                      return CheckboxListTile(
                        dense: true,
                        value: checked,
                        title: Text(row['name']?.toString() ?? '—'),
                        subtitle: Text([row['code'], row['phone']]
                            .where((e) => e != null)
                            .join(' · ')),
                        onChanged: (_) {
                          final next = {...widget.selected};

                          checked ? next.remove(id) : next.add(id);
                          widget.onChanged(next);
                        },
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}
