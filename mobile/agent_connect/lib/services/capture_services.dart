import 'dart:io';

import 'package:flutter/services.dart' show PlatformException;
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// A coordinate the phone was willing to give us.
class Fix {
  const Fix(this.latitude, this.longitude, this.accuracyMetres, this.takenAt);

  final double latitude;
  final double longitude;
  final int accuracyMetres;
  final DateTime takenAt;

  Map<String, dynamic> toPayload() => {
        'latitude': latitude,
        'longitude': longitude,
        'location_accuracy_m': accuracyMetres,
        'located_at': takenAt.toUtc().toIso8601String(),
      };

  String get pretty => '${latitude.toStringAsFixed(5)}, '
      '${longitude.toStringAsFixed(5)}  ±${accuracyMetres}m';
}

/// Where the agent is standing.
///
/// EVERY PATH RETURNS NULL RATHER THAN THROWING. A refused permission, a
/// disabled radio, a valley with no sky — all of them end with a visit that
/// still has to be recordable. The backend stores the coordinate as evidence
/// and never as a gate, and this class is the client half of that decision: it
/// is not this app's business to decide a visit did not happen because a
/// satellite was not overhead.
class LocationService {
  /// Bounded so a form cannot hang on a fix that is not coming. Fifteen seconds
  /// is a cold start on a phone that has been in a pocket; beyond that the
  /// agent is better served by saving the record without one.
  static const _timeout = Duration(seconds: 15);

  Future<Fix?> current() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;

      var permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return null;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: _timeout,
        ),
      );

      return Fix(
        position.latitude,
        position.longitude,
        position.accuracy.round(),
        position.timestamp,
      );
    } catch (_) {
      // Timeout, permission race, platform quirk — all the same answer here.
      return null;
    }
  }
}

/// A photograph could not be taken, with a reason worth showing the agent.
class PhotoUnavailable implements Exception {
  const PhotoUnavailable(this.message);

  final String message;

  @override
  String toString() => message;
}

/// The photograph, taken and shrunk before it ever reaches the queue.
///
/// COMPRESSION HAPPENS AT CAPTURE, NOT AT UPLOAD. A modern phone camera
/// produces 4–8 MB per frame. Queueing that means the agent's storage fills up
/// over a week in the field and the eventual upload is four times longer on the
/// worst connection it will ever meet. Shrinking once, immediately, costs a
/// second of CPU while the agent is still looking at the screen.
///
/// 1600px on the long edge at quality 70 lands around 200–400 KB, which is
/// legible for a household, a herd or a signed form — which is what these
/// photographs are for. The server accepts up to 8 MB; that ceiling is a
/// backstop for a phone this code did not anticipate, not the target.
class PhotoService {
  final _picker = ImagePicker();

  /// Returns the shrunk file, or throws [PhotoUnavailable] with something the
  /// agent can act on.
  ///
  /// A denied camera permission arrives as a PlatformException, and swallowing
  /// it made "Take a photo" a button that did nothing at all — no picture, no
  /// message, no way to tell a refused permission from a slow camera. The
  /// caller shows what comes back.
  Future<File?> capture({bool fromGallery = false}) async {
    try {
      final shot = await _picker.pickImage(
        source: fromGallery ? ImageSource.gallery : ImageSource.camera,
        maxWidth: 1600,
        imageQuality: 85,
      );

      // Null means the agent backed out of the camera, which is not an error.
      if (shot == null) return null;

      return await _shrink(File(shot.path));
    } on PlatformException catch (e) {
      throw PhotoUnavailable(switch (e.code) {
        'camera_access_denied' =>
          'Camera access is off for AgentConnect. Turn it on in your phone settings.',
        'photo_access_denied' =>
          'Photo access is off for AgentConnect. Turn it on in your phone settings.',
        _ => 'The camera could not be opened (${e.code}).',
      });
    }
  }

  Future<File> _shrink(File original) async {
    // The app's own directory, not the OS cache: a cache directory can be
    // reclaimed under storage pressure, and that would delete a queued photo
    // the agent believes is safe.
    final dir = Directory(p.join((await getApplicationDocumentsDirectory()).path, 'photos'));

    if (!dir.existsSync()) dir.createSync(recursive: true);

    final target = p.join(dir.path, '${DateTime.now().microsecondsSinceEpoch}.jpg');

    final result = await FlutterImageCompress.compressAndGetFile(
      original.absolute.path,
      target,
      quality: 70,
      minWidth: 1600,
      minHeight: 1600,
    );

    // If compression is unavailable on this device, the full-size original is
    // still better than no photograph.
    return result == null ? original : File(result.path);
  }
}
