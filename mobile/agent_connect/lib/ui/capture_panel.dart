import 'dart:io';

import 'package:flutter/material.dart';

import '../services/capture_services.dart';

/// The photograph-and-coordinate block, shared by both capture forms.
///
/// Both are OPTIONAL and the form says so. The backend stores a coordinate as
/// evidence and never as a gate, and this widget is the client half of that
/// decision — a visit in a valley with no sky must still be recordable, or the
/// work goes back to paper, which is the failure the whole system exists to
/// end.
///
/// The fix is taken when the panel opens rather than when the form is saved, so
/// the coordinate belongs to the place the agent was standing while filling it
/// in, and the wait happens while they are typing rather than when they press
/// Save.
class CapturePanel extends StatefulWidget {
  const CapturePanel({
    super.key,
    required this.location,
    required this.photos,
    required this.onChanged,
  });

  final LocationService location;
  final PhotoService photos;

  /// (fix, photo) — either may be null.
  final void Function(Fix?, File?) onChanged;

  @override
  State<CapturePanel> createState() => _CapturePanelState();
}

class _CapturePanelState extends State<CapturePanel> {
  Fix? _fix;
  File? _photo;
  bool _locating = true;

  @override
  void initState() {
    super.initState();
    _locate();
  }

  Future<void> _locate() async {
    setState(() => _locating = true);

    final fix = await widget.location.current();

    if (!mounted) return;

    setState(() {
      _fix = fix;
      _locating = false;
    });

    widget.onChanged(_fix, _photo);
  }

  Future<void> _takePhoto({bool fromGallery = false}) async {
    final file = await widget.photos.capture(fromGallery: fromGallery);

    if (file == null || !mounted) return;

    setState(() => _photo = file);
    widget.onChanged(_fix, _photo);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  _fix == null ? Icons.location_off_outlined : Icons.my_location,
                  size: 20,
                  color: _fix == null ? scheme.outline : scheme.primary,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _locating
                      ? const Text('Finding your position…')
                      : Text(
                          _fix?.pretty ?? 'No position — you can still save',
                          style: TextStyle(color: _fix == null ? scheme.outline : null),
                        ),
                ),
                if (!_locating)
                  IconButton(
                    tooltip: 'Try again',
                    icon: const Icon(Icons.refresh),
                    onPressed: _locate,
                  ),
              ],
            ),
            const Divider(height: 20),
            if (_photo != null) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.file(_photo!, height: 160, width: double.infinity, fit: BoxFit.cover),
              ),
              const SizedBox(height: 8),
            ],
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => _takePhoto(),
                    icon: const Icon(Icons.photo_camera_outlined),
                    label: Text(_photo == null ? 'Take a photo' : 'Retake'),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton(
                  tooltip: 'Choose from gallery',
                  icon: const Icon(Icons.photo_library_outlined),
                  onPressed: () => _takePhoto(fromGallery: true),
                ),
                if (_photo != null)
                  IconButton(
                    tooltip: 'Remove photo',
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () {
                      setState(() => _photo = null);
                      widget.onChanged(_fix, null);
                    },
                  ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'The photo is shrunk on this phone and sent after the record.',
              style: TextStyle(fontSize: 12, color: scheme.outline),
            ),
          ],
        ),
      ),
    );
  }
}
