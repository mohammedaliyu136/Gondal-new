import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../data/local_db.dart';
import '../services/sync_engine.dart';

/// What has not reached the server, and — for anything refused — why.
///
/// This screen is the reason the queue keeps failed records instead of dropping
/// them. A refusal an agent cannot read is a refusal they cannot act on: they
/// drove to the household, they did the work, and "12 waiting" that never
/// reaches zero teaches them the app is broken and the notebook is not.
///
/// So each refusal shows the SERVER's own wording, and offers the only two
/// honest actions: try it again (an administrator may have granted the
/// permission since), or throw it away deliberately. Nothing here deletes an
/// agent's work without them saying so.
class UnsentScreen extends StatefulWidget {
  const UnsentScreen({super.key, required this.db, required this.sync});

  final LocalDb db;
  final SyncEngine sync;

  @override
  State<UnsentScreen> createState() => _UnsentScreenState();
}

class _UnsentScreenState extends State<UnsentScreen> {
  late Future<List<Map<String, Object?>>> _rows;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  /// A BLOCK body, not an arrow.
  ///
  /// `setState(() => _rows = widget.db.unsent())` returns the assigned value —
  /// a Future — and Flutter asserts that the setState callback returns nothing,
  /// because a callback it cannot await is a callback whose work it cannot
  /// schedule a frame for. In debug that threw on every build of this screen;
  /// in release the assertion is skipped and it silently means something
  /// different from what it reads like. Assigning inside braces returns void.
  void _reload() {
    if (!mounted) return;

    setState(() {
      _rows = widget.db.unsent();
    });
  }

  static const _labels = {
    'farmer_validations': 'Revalidation',
    'field_visits': 'Field visit',
    'farmer_registrations': 'Farmer enrolment',
    'milk_collections': 'Milk collection',
    'oss_sales': 'Shop sale',
  };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Unsent work'),
        actions: [
          IconButton(
            tooltip: 'Try now',
            icon: const Icon(Icons.sync),
            onPressed: () async {
              await widget.sync.sync(reason: 'unsent screen');
              _reload();
            },
          ),
        ],
      ),
      body: FutureBuilder<List<Map<String, Object?>>>(
        future: _rows,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          final rows = snapshot.data ?? const [];

          if (rows.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.cloud_done_outlined, size: 48),
                    SizedBox(height: 12),
                    Text('Everything on this phone has reached the server.',
                        textAlign: TextAlign.center),
                  ],
                ),
              ),
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: rows.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) => _Row(
              row: rows[i],
              label: _labels[rows[i]['type']] ?? rows[i]['type'].toString(),
              onRetry: () async {
                await widget.db.retry(rows[i]['client_uuid'] as String);
                await widget.sync.refreshCounts();
                _reload();
                unawaited(widget.sync.sync(reason: 'retry'));
              },
              onDiscard: () async {
                await widget.db.discard(rows[i]['client_uuid'] as String);
                await widget.sync.refreshCounts();
                _reload();
              },
            ),
          );
        },
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.row,
    required this.label,
    required this.onRetry,
    required this.onDiscard,
  });

  final Map<String, Object?> row;
  final String label;
  final Future<void> Function() onRetry;
  final Future<void> Function() onDiscard;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final failed = row['status'] == LocalDb.statusFailed;
    final photos = (row['photos_waiting'] as int?) ?? 0;
    final capturedAt = DateTime.tryParse(row['captured_at'] as String? ?? '')?.toLocal();

    return Card(
      color: failed ? scheme.errorContainer.withValues(alpha: 0.35) : null,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(failed ? Icons.error_outline : Icons.schedule_send,
                    size: 20, color: failed ? scheme.error : scheme.outline),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
                if (capturedAt != null)
                  Text(DateFormat('d MMM, HH:mm').format(capturedAt),
                      style: TextStyle(fontSize: 12, color: scheme.outline)),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              _describe(row),
              style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
            ),
            if (photos > 0)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Row(
                  children: [
                    Icon(Icons.photo_outlined, size: 14, color: scheme.outline),
                    const SizedBox(width: 4),
                    Text('$photos photo(s) with it',
                        style: TextStyle(fontSize: 12, color: scheme.outline)),
                  ],
                ),
              ),
            if (failed) ...[
              const SizedBox(height: 8),
              // The server's own wording. Paraphrasing it here would mean an
              // agent reading one thing and an administrator reading another.
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: scheme.surface,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  row['error']?.toString() ?? 'Refused.',
                  style: TextStyle(fontSize: 13, color: scheme.error),
                ),
              ),
              const SizedBox(height: 8),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: () async {
                      final go = await showDialog<bool>(
                        context: context,
                        builder: (context) => AlertDialog(
                          title: const Text('Throw this away?'),
                          content: const Text(
                              'This record will be deleted from the phone and never sent. '
                              'You cannot get it back.'),
                          actions: [
                            TextButton(
                                onPressed: () => Navigator.pop(context, false),
                                child: const Text('Keep')),
                            FilledButton(
                                onPressed: () => Navigator.pop(context, true),
                                child: const Text('Delete')),
                          ],
                        ),
                      );

                      if (go == true) await onDiscard();
                    },
                    child: const Text('Discard'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton.tonal(onPressed: onRetry, child: const Text('Try again')),
                ],
              ),
            ] else
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text('Waiting for a connection — nothing to do.',
                    style: TextStyle(fontSize: 12, color: scheme.outline)),
              ),
          ],
        ),
      ),
    );
  }

  /// A line the agent recognises as the thing they typed, without dumping JSON
  /// at somebody standing in a field.
  String _describe(Map<String, Object?> row) {
    try {
      final payload = jsonDecode(row['payload'] as String) as Map<String, dynamic>;

      return switch (row['type']) {
        'farmer_validations' => 'Outcome: ${payload['outcome'] ?? '—'}'
            '${payload['findings'] == null ? '' : ' · ${payload['findings']}'}',
        'field_visits' => [
            payload['visit_date'],
            if ((payload['topics'] as List?)?.isNotEmpty ?? false) (payload['topics'] as List).first,
            payload['notes'],
          ].where((e) => e != null).join(' · '),
        _ => 'Captured on this phone.',
      };
    } catch (_) {
      return 'Captured on this phone.';
    }
  }
}
