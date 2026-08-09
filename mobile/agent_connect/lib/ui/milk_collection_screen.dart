import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../data/repositories.dart';
import '../services/sync_engine.dart';

/// The morning round: one farmer's milk, at one point.
///
/// THE ARITHMETIC IS SHOWN, NOT TYPED. BR-6 — accepted = presented − rejected —
/// is computed by the server, and this screen displays the same sum live so the
/// agent and the farmer are looking at the number before it is saved. An agent
/// who cannot see what a rejection costs is being asked to enter it blind, in
/// front of the person it costs.
///
/// THE CUT-OFF IS EXPLAINED BEFORE IT REFUSES. BR-3 — milk presented after the
/// point's cut-off may only be recorded rejected-in-full for the late reason, or
/// accepted by somebody holding the override. The phone knows the cut-off from
/// `form-options`, so it says so on the form rather than letting the agent fill
/// everything in and discover the refusal at sync time, hours later and miles
/// away. The rule is still enforced by the server; this only stops the agent
/// wasting a walk.
///
/// The override is deliberately NOT offered here. It sits behind
/// `milk.deliveries.cutoff_override`, which the Supervisor and Collection
/// Officer hold and the agent does not, precisely so the person carrying the
/// late milk cannot authorise accepting it.
class MilkCollectionScreen extends StatefulWidget {
  const MilkCollectionScreen({
    super.key,
    required this.fieldData,
    required this.capture,
    required this.sync,
  });

  final FieldDataRepository fieldData;
  final CaptureRepository capture;
  final SyncEngine sync;

  @override
  State<MilkCollectionScreen> createState() => _MilkCollectionScreenState();
}

class _MilkCollectionScreenState extends State<MilkCollectionScreen> {
  late Future<({List<dynamic> points, List<dynamic> reasons, List<dynamic> farmers})> _data;

  int? _pointId;
  String? _pointName;
  String _cutoff = '07:00';

  int? _farmerId;
  final _presented = TextEditingController();
  final _rejected = TextEditingController(text: '0');
  int? _reasonId;
  final _containers = TextEditingController();
  final _notes = TextEditingController();

  DateTime _deliveredAt = DateTime.now();
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _data = _load();
    _presented.addListener(_recompute);
    _rejected.addListener(_recompute);
  }

  @override
  void dispose() {
    _presented.dispose();
    _rejected.dispose();
    _containers.dispose();
    _notes.dispose();
    super.dispose();
  }

  void _recompute() => setState(() {});

  Future<({List<dynamic> points, List<dynamic> reasons, List<dynamic> farmers})> _load() async {
    final options = await widget.fieldData.formOptions();
    final data = (options['data'] as Map<String, dynamic>?) ?? const {};
    final points = (data['collection_points'] as List?) ?? const [];

    // One point is the ordinary case — an agent runs one. Pre-select it so the
    // morning queue is farmer-name-then-litres and nothing else.
    if (points.length == 1 && _pointId == null) {
      _pointId = points.first['id'] as int;
      _pointName = points.first['name']?.toString();
      _cutoff = points.first['cutoff_time']?.toString() ?? '07:00';
    }

    return (
      points: points,
      reasons: (data['rejection_reasons'] as List?) ?? const [],
      farmers: await widget.fieldData.farmers(),
    );
  }

  double get _presentedLitres => double.tryParse(_presented.text.trim()) ?? 0;
  double get _rejectedLitres => double.tryParse(_rejected.text.trim()) ?? 0;
  double get _acceptedLitres => (_presentedLitres - _rejectedLitres).clamp(0, double.infinity);

  /// Is the captured time past this point's cut-off?
  bool get _isLate {
    final parts = _cutoff.split(':');

    if (parts.length < 2) return false;

    final cutoff = DateTime(
      _deliveredAt.year,
      _deliveredAt.month,
      _deliveredAt.day,
      int.tryParse(parts[0]) ?? 7,
      int.tryParse(parts[1]) ?? 0,
    );

    return _deliveredAt.isAfter(cutoff);
  }

  /// BR-3 — after the cut-off, the only thing an agent may record is a full
  /// rejection for the late reason.
  bool get _lateReasonChosen {
    if (_reasonId == null) return false;

    return _lateReasonId == _reasonId;
  }

  int? _lateReasonId;

  bool get _blockedByCutoff =>
      _isLate && !(_lateReasonChosen && _rejectedLitres == _presentedLitres && _presentedLitres > 0);

  Future<void> _save() async {
    if (_pointId == null || _farmerId == null || _presentedLitres <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Choose a farmer and enter the litres presented.')),
      );

      return;
    }

    if (_rejectedLitres > 0 && _reasonId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        // BR-1 — a rejection without a configured reason is not a rejection.
        const SnackBar(content: Text('Rejected milk needs a reason.')),
      );

      return;
    }

    setState(() => _saving = true);

    try {
      await widget.capture.recordDelivery(
        collectionPointId: _pointId!,
        farmerId: _farmerId!,
        litresPresented: _presented.text.trim(),
        litresRejected: _rejected.text.trim().isEmpty ? '0' : _rejected.text.trim(),
        rejectionReasonId: _rejectedLitres > 0 ? _reasonId : null,
        containers: int.tryParse(_containers.text.trim()),
        notes: _notes.text,
        deliveredAt: _deliveredAt,
      );

      await widget.sync.refreshCounts();
      unawaited(widget.sync.sync(reason: 'after delivery'));

      if (!mounted) return;

      // Save & next: the queue at a point is several farmers back to back, and
      // returning to the home screen between each is the wrong shape entirely.
      setState(() {
        _farmerId = null;
        _presented.clear();
        _rejected.text = '0';
        _reasonId = null;
        _containers.clear();
        _notes.clear();
        _deliveredAt = DateTime.now();
      });

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Saved on this phone. Next farmer.')),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not save on this phone: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(_pointName == null ? 'Record a delivery' : 'Delivery · $_pointName')),
      body: FutureBuilder<({List<dynamic> points, List<dynamic> reasons, List<dynamic> farmers})>(
        future: _data,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError || (snapshot.data?.points.isEmpty ?? true)) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Text(
                  'No collection point on this phone yet.\n'
                  'Connect once so the app can download your point and its farmers.',
                  textAlign: TextAlign.center,
                ),
              ),
            );
          }

          final data = snapshot.data!;
          _lateReasonId ??= _findLateReason(data.reasons);

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (data.points.length > 1) ...[
                DropdownButtonFormField<int>(
                  initialValue: _pointId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Collection point'),
                  items: [
                    for (final p in data.points)
                      DropdownMenuItem(value: p['id'] as int, child: Text('${p['code']} · ${p['name']}')),
                  ],
                  onChanged: (v) => setState(() {
                    _pointId = v;
                    final p = data.points.firstWhere((e) => e['id'] == v);
                    _pointName = p['name']?.toString();
                    _cutoff = p['cutoff_time']?.toString() ?? '07:00';
                  }),
                ),
                const SizedBox(height: 12),
              ],

              DropdownButtonFormField<int>(
                initialValue: _farmerId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Farmer'),
                items: [
                  for (final f in data.farmers)
                    DropdownMenuItem(
                      value: f['id'] as int,
                      child: Text('${f['name']}  (${f['code']})', overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => setState(() => _farmerId = v),
              ),
              const SizedBox(height: 16),

              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _presented,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(labelText: 'Litres presented', suffixText: 'L'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      controller: _rejected,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(labelText: 'Rejected', suffixText: 'L'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),

              // BR-6, in front of both people, before it is saved.
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: scheme.primaryContainer.withValues(alpha: 0.45),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  'Accepted: ${_acceptedLitres.toStringAsFixed(2)} L'
                  '${_rejectedLitres > 0 ? '   (${_presentedLitres.toStringAsFixed(2)} − ${_rejectedLitres.toStringAsFixed(2)})' : ''}',
                  style: TextStyle(fontWeight: FontWeight.w700, color: scheme.onPrimaryContainer),
                ),
              ),

              if (_rejectedLitres > 0) ...[
                const SizedBox(height: 12),
                DropdownButtonFormField<int>(
                  initialValue: _reasonId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Why was it rejected?'),
                  items: [
                    for (final r in data.reasons)
                      DropdownMenuItem(
                        value: r['id'] as int,
                        child: Text(r['name']?.toString() ?? '—', overflow: TextOverflow.ellipsis),
                      ),
                  ],
                  onChanged: (v) => setState(() => _reasonId = v),
                ),
                const SizedBox(height: 4),
                Text(
                  'Rejected milk is not paid for and does not travel.',
                  style: TextStyle(fontSize: 12, color: scheme.outline),
                ),
              ],

              const SizedBox(height: 16),
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(_isLate ? Icons.warning_amber : Icons.schedule,
                    color: _isLate ? scheme.error : null),
                title: Text(DateFormat('EEE d MMM, HH:mm').format(_deliveredAt)),
                subtitle: Text('Point cut-off $_cutoff'),
                trailing: const Icon(Icons.edit),
                onTap: () async {
                  final picked = await showTimePicker(
                    context: context,
                    initialTime: TimeOfDay.fromDateTime(_deliveredAt),
                  );

                  if (picked == null) return;

                  setState(() => _deliveredAt = DateTime(
                        _deliveredAt.year, _deliveredAt.month, _deliveredAt.day,
                        picked.hour, picked.minute,
                      ));
                },
              ),

              if (_isLate)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: scheme.errorContainer.withValues(alpha: 0.4),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    _blockedByCutoff
                        // Says what to DO, not just that it is refused.
                        ? 'This is after the $_cutoff cut-off. Reject it in full for '
                            '"failure to meet delivery time", or ask a supervisor to accept it — '
                            'they hold that authority, you do not.'
                        : 'After the cut-off, and rejected in full for late delivery. This will be accepted.',
                    style: TextStyle(fontSize: 13, color: scheme.onErrorContainer),
                  ),
                ),

              const SizedBox(height: 16),
              TextField(
                controller: _containers,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Containers (optional)'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _notes,
                maxLines: 2,
                decoration: const InputDecoration(labelText: 'Notes (optional)'),
              ),

              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: (_saving || _blockedByCutoff) ? null : _save,
                icon: const Icon(Icons.save_outlined),
                label: const Text('Save & next farmer'),
              ),
              const SizedBox(height: 8),
              Text(
                'Saved here immediately. It sends by itself when you have a connection.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: scheme.outline),
              ),
            ],
          );
        },
      ),
    );
  }

  /// BR-3's late reason is identified by the server's own flag, never by
  /// matching a code — §18.7 keeps reference data out of the client.
  /// `form-options` does not send the flag, so the code is the only handle
  /// available and the fallback is to treat nothing as late-specific.
  int? _findLateReason(List<dynamic> reasons) {
    for (final r in reasons) {
      if ((r['code']?.toString() ?? '').toUpperCase().contains('LATE')) {
        return r['id'] as int;
      }
    }

    return null;
  }
}
