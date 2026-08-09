import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';

import '../data/repositories.dart';
import '../services/capture_services.dart';
import '../services/sync_engine.dart';
import 'capture_panel.dart';

/// BR-36 — the revalidations M&E assigned to this worker.
///
/// The queue is fetched when there is a connection and served from cache when
/// there is not, because an agent who drove to Gengle needs the list they were
/// given in Yola.
class ValidationsScreen extends StatefulWidget {
  const ValidationsScreen({
    super.key,
    required this.fieldData,
    required this.capture,
    required this.location,
    required this.photos,
    required this.sync,
  });

  final FieldDataRepository fieldData;
  final CaptureRepository capture;
  final LocationService location;
  final PhotoService photos;
  final SyncEngine sync;

  @override
  State<ValidationsScreen> createState() => _ValidationsScreenState();
}

class _ValidationsScreenState extends State<ValidationsScreen> {
  late Future<List<dynamic>> _queue;

  /// Answered on this phone but not yet gone. They stay struck through in the
  /// list rather than vanishing — an item that disappears before it has
  /// actually reached anyone reads as "sent", which it is not.
  final _answered = <int>{};

  @override
  void initState() {
    super.initState();
    _queue = widget.fieldData.assignedValidations();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Revalidations')),
      body: RefreshIndicator(
        onRefresh: () async {
          setState(() => _queue = widget.fieldData.assignedValidations());
          await _queue;
        },
        child: FutureBuilder<List<dynamic>>(
          future: _queue,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              return const _Message(
                icon: Icons.cloud_off,
                text: 'No queue on this phone yet.\nConnect once to download your assignments.',
              );
            }

            final items = snapshot.data ?? const [];

            if (items.isEmpty) {
              return const _Message(icon: Icons.done_all, text: 'Nothing assigned to you.');
            }

            return ListView.separated(
              padding: const EdgeInsets.all(12),
              itemCount: items.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final item = items[i] as Map<String, dynamic>;
                final id = (item['id'] ?? item['validation_id']) as int;
                final done = _answered.contains(id);

                return Card(
                  child: ListTile(
                    title: Text(
                      (item['farmer']?['name'] ?? item['farmer_name'] ?? 'Farmer').toString(),
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        decoration: done ? TextDecoration.lineThrough : null,
                      ),
                    ),
                    subtitle: Text([
                      item['reference'],
                      item['farmer']?['community'] ?? item['community'],
                      if (item['due_on'] != null) 'due ${item['due_on']}',
                    ].where((e) => e != null).join(' · ')),
                    trailing: done
                        ? const Icon(Icons.schedule_send, size: 20)
                        : const Icon(Icons.chevron_right),
                    onTap: done ? null : () => _answer(item, id),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }

  Future<void> _answer(Map<String, dynamic> item, int id) async {
    final farmerId = (item['farmer']?['id'] ?? item['farmer_id']) as int;

    final saved = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => _ValidationForm(
          item: item,
          validationId: id,
          farmerId: farmerId,
          capture: widget.capture,
          location: widget.location,
          photos: widget.photos,
          sync: widget.sync,
        ),
      ),
    );

    if (saved == true && mounted) setState(() => _answered.add(id));
  }
}

class _ValidationForm extends StatefulWidget {
  const _ValidationForm({
    required this.item,
    required this.validationId,
    required this.farmerId,
    required this.capture,
    required this.location,
    required this.photos,
    required this.sync,
  });

  final Map<String, dynamic> item;
  final int validationId;
  final int farmerId;
  final CaptureRepository capture;
  final LocationService location;
  final PhotoService photos;
  final SyncEngine sync;

  @override
  State<_ValidationForm> createState() => _ValidationFormState();
}

class _ValidationFormState extends State<_ValidationForm> {
  /// The server's vocabulary, not ours. `not_found` and `refused` close the
  /// assignment honestly but leave the farmer overdue — and therefore leave
  /// BR-36's payment hold in place, which is the intended outcome.
  static const _outcomes = {
    'confirmed': 'Details are correct',
    'corrected': 'I corrected something',
    'not_found': 'Could not find the farmer',
    'refused': 'Farmer refused',
  };

  String _outcome = 'confirmed';
  final _findings = TextEditingController();
  final _phone = TextEditingController();
  final _herd = TextEditingController();
  final _lactating = TextEditingController();

  Fix? _fix;
  File? _photo;
  bool _saving = false;

  @override
  void dispose() {
    _findings.dispose();
    _phone.dispose();
    _herd.dispose();
    _lactating.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);

    /*
     * try/finally, because the flag latches otherwise. A full disk or a
     * database error would leave `_saving` true forever, the Save button
     * permanently disabled, and no message on screen — the agent's only way
     * out being to kill the app and lose what they typed.
     */
    try {
      // Written locally first, and the screen closes on that. Nothing here
      // waits on a network the agent may not have for hours.
      final clientUuid = await widget.capture.submitValidation(
        validationId: widget.validationId,
        farmerId: widget.farmerId,
        outcome: _outcome,
        findings: _findings.text,
        phone: _phone.text,
        herdSize: int.tryParse(_herd.text),
        lactatingCount: int.tryParse(_lactating.text),
        fix: _fix?.toPayload(),
      );

      if (_photo != null) {
        await widget.capture.attachPhoto(clientUuid, _photo!.path, caption: 'Revalidation');
      }

      await widget.sync.refreshCounts();
      unawaited(widget.sync.sync(reason: 'after capture'));

      if (mounted) Navigator.pop(context, true);
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
    final correcting = _outcome == 'corrected';

    return Scaffold(
      appBar: AppBar(
        title: Text((widget.item['farmer']?['name'] ?? 'Revalidation').toString()),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          CapturePanel(
            location: widget.location,
            photos: widget.photos,
            onChanged: (fix, photo) {
              _fix = fix;
              _photo = photo;
            },
          ),
          const SizedBox(height: 16),

          Text('What did you find?', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 8),
          // The group value and the change handler live on the ancestor now;
          // per-tile `groupValue`/`onChanged` are deprecated.
          RadioGroup<String>(
            groupValue: _outcome,
            onChanged: (v) => setState(() => _outcome = v!),
            child: Column(
              children: [
                for (final e in _outcomes.entries)
                  RadioListTile<String>(
                    value: e.key,
                    title: Text(e.value),
                    contentPadding: EdgeInsets.zero,
                  ),
              ],
            ),
          ),

          if (correcting) ...[
            const SizedBox(height: 8),
            // Only the fields `community.farmers.validate` is allowed to
            // change. Cooperative and collection point are deliberately absent:
            // those move money, and they belong to community.farmers.edit.
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone'),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _herd,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Herd size'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _lactating,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Lactating'),
                  ),
                ),
              ],
            ),
          ],

          const SizedBox(height: 16),
          TextField(
            controller: _findings,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Notes',
              hintText: 'What you saw, and anything M&E should know',
            ),
          ),

          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: const Icon(Icons.save_outlined),
            label: const Text('Save on this phone'),
          ),
          const SizedBox(height: 8),
          Text(
            'Saved here immediately. It sends by itself when you have a connection.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.outline),
          ),
        ],
      ),
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return ListView(
      children: [
        const SizedBox(height: 100),
        Icon(icon, size: 44, color: Theme.of(context).colorScheme.outline),
        const SizedBox(height: 12),
        Text(text, textAlign: TextAlign.center),
      ],
    );
  }
}
