import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../data/repositories.dart';
import '../services/capture_services.dart';
import '../services/sync_engine.dart';
import 'capture_panel.dart';
import 'farmer_picker.dart';

/// A household visit, a training session, a demonstration.
///
/// The community list comes from the server's `form-options` and is cached, so
/// this form opens and works with no connection — which is the only condition
/// it will ever be used in.
class FieldVisitScreen extends StatefulWidget {
  const FieldVisitScreen({
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
  State<FieldVisitScreen> createState() => _FieldVisitScreenState();
}

class _FieldVisitScreenState extends State<FieldVisitScreen> {
  late Future<Map<String, dynamic>> _options;

  int? _communityId;
  DateTime _visitDate = DateTime.now();
  final _notes = TextEditingController();
  final _topic = TextEditingController();

  /// Loaded when a community is chosen, and cached per community so the picker
  /// works where there is no signal.
  List<dynamic> _farmers = const [];
  Set<int> _selectedFarmers = {};
  bool _loadingFarmers = false;

  Fix? _fix;
  File? _photo;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _options = widget.fieldData.formOptions();
  }

  @override
  void dispose() {
    _notes.dispose();
    _topic.dispose();
    super.dispose();
  }

  /// Changing community empties the selection deliberately: a household
  /// selected in Tudun Wada is not one that was reached in Karewa, and carrying
  /// it over would attach farmers to a visit they were not part of.
  Future<void> _onCommunityChanged(int? id) async {
    setState(() {
      _communityId = id;
      _selectedFarmers = {};
      _farmers = const [];
      _loadingFarmers = id != null;
    });

    if (id == null) return;

    List<dynamic> farmers;

    try {
      farmers = await widget.fieldData.farmersIn(id);
    } catch (_) {
      // Never seen this community online. The visit is still recordable — it
      // just cannot name households, and the picker says so.
      farmers = const [];
    }

    if (mounted) {
      setState(() {
        _farmers = farmers;
        _loadingFarmers = false;
      });
    }
  }

  Future<void> _save() async {
    if (_communityId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Choose the community you visited.')),
      );

      return;
    }

    setState(() => _saving = true);

    /*
     * The server counts this list to get `farmers_reached`, and fills its
     * single-farmer column when there is exactly one — which is what links a
     * visit to a quality follow-up. So households are NAMED, never counted:
     * a typed number would be filtered away server-side and save as zero, and
     * eleven names are more use to M&E than the number eleven anyway.
     */
    // try/finally — see the note on _ValidationFormState._save.
    try {
      final clientUuid = await widget.capture.logFieldVisit(
        communityId: _communityId!,
        visitDate: DateFormat('yyyy-MM-dd').format(_visitDate),
        topics: _topic.text.trim().isEmpty ? const [] : [_topic.text.trim()],
        notes: _notes.text,
        farmerIds: _selectedFarmers.toList(),
        fix: _fix?.toPayload(),
      );

      if (_photo != null) {
        await widget.capture.attachPhoto(clientUuid, _photo!.path, caption: 'Field visit');
      }

      await widget.sync.refreshCounts();
      unawaited(widget.sync.sync(reason: 'after capture'));

      if (mounted) {
        Navigator.pop(context);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Visit saved on this phone.')),
        );
      }
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
    return Scaffold(
      appBar: AppBar(title: const Text('Field visit')),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _options,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          final communities =
              (snapshot.data?['data']?['communities'] as List? ?? const []).cast<dynamic>();

          if (communities.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Text(
                  'No communities on this phone yet.\n'
                  'Connect once so the app can download them.',
                  textAlign: TextAlign.center,
                ),
              ),
            );
          }

          return ListView(
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

              DropdownButtonFormField<int>(
                initialValue: _communityId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Community'),
                items: [
                  for (final c in communities)
                    DropdownMenuItem(
                      value: c['id'] as int,
                      child: Text('${c['name']}${c['lga'] == null ? '' : ' · ${c['lga']}'}'),
                    ),
                ],
                onChanged: _onCommunityChanged,
              ),
              const SizedBox(height: 12),

              if (_loadingFarmers)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: LinearProgressIndicator(),
                )
              else if (_communityId != null)
                FarmerPicker(
                  farmers: _farmers,
                  selected: _selectedFarmers,
                  onChanged: (next) => setState(() => _selectedFarmers = next),
                ),
              const SizedBox(height: 12),

              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.event_outlined),
                title: const Text('Visit date'),
                subtitle: Text(DateFormat('EEE, d MMM yyyy').format(_visitDate)),
                trailing: const Icon(Icons.edit_calendar_outlined),
                onTap: () async {
                  final picked = await showDatePicker(
                    context: context,
                    initialDate: _visitDate,
                    // A visit cannot be in the future, and one from last month
                    // is a record somebody forgot to enter, not a typo.
                    firstDate: DateTime.now().subtract(const Duration(days: 60)),
                    lastDate: DateTime.now(),
                  );

                  if (picked != null) setState(() => _visitDate = picked);
                },
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _topic,
                decoration: const InputDecoration(
                  labelText: 'Topic',
                  hintText: 'Clean milk production, feeding, animal health…',
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: _notes,
                maxLines: 5,
                decoration: const InputDecoration(
                  labelText: 'What happened',
                  hintText: 'Findings, questions raised, anything to follow up',
                ),
              ),

              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: const Icon(Icons.save_outlined),
                label: const Text('Save on this phone'),
              ),
            ],
          );
        },
      ),
    );
  }
}
