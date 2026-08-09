import 'package:flutter/material.dart';

import '../data/local_db.dart';
import '../data/repositories.dart';
import '../services/capture_services.dart';
import '../services/sync_engine.dart';
import 'field_visit_screen.dart';
import 'milk_collection_screen.dart';
import 'unsent_screen.dart';
import 'validations_screen.dart';

/// The agent's day: what M&E has asked for, what has not gone yet.
class HomeScreen extends StatefulWidget {
  const HomeScreen({
    super.key,
    required this.db,
    required this.auth,
    required this.fieldData,
    required this.capture,
    required this.location,
    required this.photos,
    required this.sync,
    required this.onSignedOut,
  });

  final LocalDb db;
  final AuthRepository auth;
  final FieldDataRepository fieldData;
  final CaptureRepository capture;
  final LocationService location;
  final PhotoService photos;
  final SyncEngine sync;
  final VoidCallback onSignedOut;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  Map<String, dynamic>? _me;

  /// What the server says this agent may do. Cached, so the home screen is
  /// still correctly shaped on a phone that has been out of signal for a week.
  Map<String, dynamic> _can = const {};

  @override
  void initState() {
    super.initState();

    widget.auth.cachedUser().then((user) {
      if (mounted) setState(() => _me = user);
    });

    /*
     * The tiles are built from `/me`, not offered to everyone. An Extension
     * Agent holds no milk.deliveries.create; showing them "Record a delivery"
     * means they fill in a farmer, litres and a rejection reason and find out
     * hours later — in another village, at sync time — that the server was
     * never going to take it.
     */
    widget.fieldData.me().then((body) {
      final data = (body['data'] as Map<String, dynamic>?) ?? const {};
      final permissions = (data['permissions'] as Map<String, dynamic>?) ?? const {};

      if (mounted) {
        setState(() {
          _can = permissions;
          _me ??= {'name': data['user']?['name'] ?? data['name']};
        });
      }
    }).catchError((_) {
      // Offline on a fresh install: show everything rather than an empty home
      // screen. The server still refuses what it should — this only decides
      // what is worth offering.
      if (mounted) setState(() => _can = const {});
    });
  }

  /// Unknown means "not yet told", which shows the tile. Only an explicit
  /// `false` from the server hides one.
  bool _allows(String flag) => _can[flag] != false;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_me?['name']?.toString() ?? 'AgentConnect'),
        actions: [
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout),
            onPressed: () async {
              final summary = widget.sync.queue;

              // Signing out does not delete the queue, but the agent should
              // know it is leaving with work still on the phone.
              if (summary.pending > 0 || summary.photos > 0) {
                final go = await showDialog<bool>(
                  context: context,
                  builder: (context) => AlertDialog(
                    title: const Text('Unsent work'),
                    content: Text('${summary.pending} record(s) and ${summary.photos} photo(s) '
                        'have not reached the server. They stay on this phone and will '
                        'send when you sign in again.'),
                    actions: [
                      TextButton(
                          onPressed: () => Navigator.pop(context, false),
                          child: const Text('Stay')),
                      FilledButton(
                          onPressed: () => Navigator.pop(context, true),
                          child: const Text('Sign out')),
                    ],
                  ),
                );

                if (go != true) return;
              }

              await widget.auth.signOut();
              widget.onSignedOut();
            },
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(38),
          child: _SyncBar(sync: widget.sync),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_allows('can_record_milk_intake'))
            _Tile(
              icon: Icons.water_drop_outlined,
              title: 'Record a delivery',
              subtitle: 'A farmer\'s milk at your collection point',
              onTap: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => MilkCollectionScreen(
                    fieldData: widget.fieldData,
                    capture: widget.capture,
                    sync: widget.sync,
                  ),
                ),
              ),
            ),
          if (_allows('can_validate_farmers'))
            _Tile(
            icon: Icons.fact_check_outlined,
            title: 'Revalidations',
            subtitle: 'Farmers M&E has asked you to check',
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => ValidationsScreen(
                  fieldData: widget.fieldData,
                  capture: widget.capture,
                  location: widget.location,
                  photos: widget.photos,
                  sync: widget.sync,
                ),
              ),
            ),
          ),
          if (_allows('can_log_field_visits'))
            _Tile(
            icon: Icons.groups_outlined,
            title: 'Log a field visit',
            subtitle: 'A household visit, training session or demonstration',
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => FieldVisitScreen(
                  fieldData: widget.fieldData,
                  capture: widget.capture,
                  location: widget.location,
                  photos: widget.photos,
                  sync: widget.sync,
                ),
              ),
            ),
          ),
          AnimatedBuilder(
            animation: widget.sync,
            builder: (context, _) {
              final q = widget.sync.queue;

              return _Tile(
                icon: q.failed > 0 ? Icons.error_outline : Icons.outbox_outlined,
                title: 'Unsent work',
                subtitle: q.pending + q.photos + q.failed == 0
                    ? 'Everything has reached the server'
                    : '${q.pending} waiting · ${q.photos} photo(s)'
                        '${q.failed > 0 ? ' · ${q.failed} refused' : ''}',
                onTap: () async {
                  await Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => UnsentScreen(db: widget.db, sync: widget.sync),
                    ),
                  );
                  await widget.sync.refreshCounts();
                },
              );
            },
          ),
        ],
      ),
    );
  }
}

/// Always visible, because "has my work gone?" is the question a field worker
/// asks most and the one an offline app most often leaves unanswered.
class _SyncBar extends StatelessWidget {
  const _SyncBar({required this.sync});

  final SyncEngine sync;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: sync,
      builder: (context, _) {
        final q = sync.queue;
        final scheme = Theme.of(context).colorScheme;

        final (IconData icon, String text, Color colour) = switch (sync.state) {
          SyncState.running => (Icons.sync, 'Sending…', scheme.primary),
          SyncState.offline => (
              Icons.cloud_off,
              q.pending + q.photos == 0
                  ? 'Offline — everything is sent'
                  : 'Offline — ${q.pending} record(s), ${q.photos} photo(s) waiting',
              scheme.outline
            ),
          SyncState.failed => (Icons.error_outline, sync.lastError ?? 'Sync problem', scheme.error),
          SyncState.idle => q.pending + q.photos == 0
              ? (Icons.cloud_done, 'Everything is sent', scheme.primary)
              : (
                  Icons.cloud_upload_outlined,
                  '${q.pending} record(s), ${q.photos} photo(s) waiting',
                  scheme.tertiary
                ),
        };

        return InkWell(
          onTap: () => sync.sync(),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: [
                Icon(icon, size: 18, color: colour),
                const SizedBox(width: 8),
                Expanded(child: Text(text, style: TextStyle(color: colour, fontSize: 13))),
                if (q.failed > 0)
                  Text('${q.failed} refused',
                      style: TextStyle(color: scheme.error, fontSize: 13)),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        leading: Icon(icon, size: 32),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
      ),
    );
  }
}
