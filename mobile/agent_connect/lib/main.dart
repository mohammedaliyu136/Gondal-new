import 'package:flutter/material.dart';

import 'core/api_client.dart';
import 'data/local_db.dart';
import 'data/repositories.dart';
import 'services/capture_services.dart';
import 'services/sync_engine.dart';
import 'ui/home_screen.dart';
import 'ui/login_screen.dart';

/// Where the server lives.
///
/// Compile-time, not a settings screen: an agent should never be able to point
/// the app at the wrong network by mistyping a host in a field, and a build for
/// the pilot is a different artefact from a build for production.
///
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8001', // the host machine, from an Android emulator
);

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final db = await LocalDb.open();
  final api = ApiClient(baseUrl: apiBaseUrl);

  runApp(AgentConnectApp(db: db, api: api));
}

class AgentConnectApp extends StatefulWidget {
  const AgentConnectApp({super.key, required this.db, required this.api});

  final LocalDb db;
  final ApiClient api;

  @override
  State<AgentConnectApp> createState() => _AgentConnectAppState();
}

class _AgentConnectAppState extends State<AgentConnectApp> {
  late final AuthRepository auth = AuthRepository(widget.api, widget.db);
  late final FieldDataRepository fieldData = FieldDataRepository(widget.api, widget.db);
  late final CaptureRepository capture = CaptureRepository(widget.db);
  late final LocationService location = LocationService();
  late final PhotoService photos = PhotoService();

  late final SyncEngine sync = SyncEngine(
    db: widget.db,
    api: widget.api,
    onAuthFailure: _signedOutByServer,
  );

  bool? _signedIn;

  @override
  void initState() {
    super.initState();
    sync.start();
    auth.isSignedIn.then((value) async {
      if (value) await _adoptOwner();

      if (mounted) setState(() => _signedIn = value);
    });
  }

  /// Tell the queue whose it is.
  ///
  /// A field phone gets handed between agents at a collection point. Without
  /// this, whoever signs in next drains the previous agent's captures under
  /// their own token — the records are not lost, they are filed against the
  /// wrong person, under the wrong data scope, with the wrong name in the
  /// audit trail. Set on every sign-in and on resume, cleared on sign-out.
  Future<void> _adoptOwner() async {
    final email = (await auth.cachedUser())?['email']?.toString() ?? '';

    capture.owner = email;
    sync.owner = email;
  }

  @override
  void dispose() {
    sync.dispose();
    super.dispose();
  }

  /// The server stopped accepting the token mid-sync.
  ///
  /// The queue is deliberately left alone. Those records are the agent's
  /// morning, and a token expiring is not a reason to lose them — they sync on
  /// the next sign-in, with their original client_uuids, so nothing duplicates.
  void _signedOutByServer() {
    capture.owner = '';
    sync.owner = '';

    if (mounted) setState(() => _signedIn = false);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Gondal AgentConnect',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0F7A5A)),
        // Field phones are used outdoors in Adamawa sun, at arm's length, often
        // one-handed. Bigger targets than a desk app would use.
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
        filledButtonTheme: FilledButtonThemeData(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
        ),
      ),
      home: switch (_signedIn) {
        null => const Scaffold(body: Center(child: CircularProgressIndicator())),
        false => LoginScreen(
            auth: auth,
            onSignedIn: () async {
              await _adoptOwner();

              if (mounted) setState(() => _signedIn = true);
            },
          ),
        true => HomeScreen(
            db: widget.db,
            auth: auth,
            fieldData: fieldData,
            capture: capture,
            location: location,
            photos: photos,
            sync: sync,
            onSignedOut: () {
              // The queue keeps its owner stamps; only the live identity clears,
              // so the next agent cannot sync work that is not theirs.
              capture.owner = '';
              sync.owner = '';
              setState(() => _signedIn = false);
            },
          ),
      },
    );
  }
}
