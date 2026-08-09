import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../data/repositories.dart';

/// AUTH-1, both steps, on one screen.
///
/// The code field appears only when the server asks for it — which, thanks to
/// AUTH-2's device token, is once per phone rather than once per morning. An
/// agent in Gengle has no inbox, so a build that asked for an emailed code
/// every day would be a build that does not work where it is used.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.auth, required this.onSignedIn});

  final AuthRepository auth;
  final VoidCallback onSignedIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _busy = false;
  String? _message;

  /// Non-null once the server has asked for a code. It IS the second step's
  /// key — single-use, minted server-side, and unreconstructable here — so
  /// holding it is what makes verification possible at all.
  String? _challenge;
  String? _maskedEmail;

  bool get _needsCode => _challenge != null;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _run(Future<LoginResult> Function() action) async {
    setState(() {
      _busy = true;
      _message = null;
    });

    try {
      final result = await action();

      switch (result.outcome) {
        case LoginOutcome.signedIn:
          widget.onSignedIn();
        case LoginOutcome.codeRequired:
          setState(() {
            _challenge = result.challenge;
            _maskedEmail = result.maskedEmail;
            _message = result.message ?? 'Enter the 6-digit code we sent you.';
          });
        case LoginOutcome.refused:
          setState(() => _message = result.message ?? 'Those details were not accepted.');
      }
    } on ApiException catch (e) {
      /*
       * The server answered and said no. Show ITS words: a wrong password, a
       * locked account and an expired code are three different problems with
       * three different remedies, and this screen used to report all of them —
       * and every other 4xx — as "could not reach the server". An agent with
       * full signal was told to find better signal.
       */
      setState(() => _message = e.message);
    } catch (e) {
      // Genuinely no answer. Sign-in is the one thing the app cannot do
      // offline, so it says so plainly rather than blaming the credentials.
      setState(() => _message = 'Could not reach the server. Sign in needs a connection once.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Icon(Icons.grass, size: 56),
                const SizedBox(height: 12),
                Text('Gondal AgentConnect',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 4),
                Text('Field register — Adamawa',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium),
                const SizedBox(height: 28),

                TextField(
                  controller: _email,
                  enabled: !_needsCode,
                  keyboardType: TextInputType.emailAddress,
                  autocorrect: false,
                  decoration: const InputDecoration(labelText: 'Email'),
                ),
                const SizedBox(height: 12),

                if (!_needsCode)
                  TextField(
                    controller: _password,
                    obscureText: true,
                    decoration: const InputDecoration(labelText: 'Password'),
                  ),

                if (_needsCode)
                  TextField(
                    controller: _code,
                    keyboardType: TextInputType.number,
                    autofocus: true,
                    maxLength: 6,
                    decoration: InputDecoration(
                      labelText: '6-digit code',
                      helperText: _maskedEmail == null
                          ? 'Only asked for the first time on this phone.'
                          : 'Sent to $_maskedEmail. Only asked the first time on this phone.',
                    ),
                  ),

                if (_message != null) ...[
                  const SizedBox(height: 12),
                  Text(_message!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                ],

                const SizedBox(height: 20),
                FilledButton(
                  onPressed: _busy
                      ? null
                      : () => _run(() => _needsCode
                          ? widget.auth.verify(_challenge!, _code.text.trim())
                          : widget.auth.login(_email.text.trim(), _password.text)),
                  child: _busy
                      ? const SizedBox(
                          height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(_needsCode ? 'Verify' : 'Sign in'),
                ),

                if (_needsCode)
                  TextButton(
                    onPressed: _busy ? null : () => setState(() => _challenge = null),
                    child: const Text('Back'),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
