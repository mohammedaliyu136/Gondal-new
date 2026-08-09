import 'package:agent_connect/ui/farmer_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The picker decides `farmers_reached`, and the server counts what it sends.
///
/// In its own file deliberately. `flutter test` gives each FILE its own
/// isolate; sharing one with the sqflite-backed screen tests meant the ffi
/// isolate's real timers leaked into this fake-async zone and every
/// pumpAndSettle here — in tests that touch no database at all — waited out its
/// ten-minute timeout.
void main() {
  group('FarmerPicker', () {
    final farmers = [
      {'id': 1, 'name': 'Adamu Bobbo', 'code': 'FRM-0001', 'phone': '08030000001'},
      {'id': 2, 'name': 'Asabe Ardo', 'code': 'FRM-0002', 'phone': '08030000002'},
      {'id': 3, 'name': 'Umaru Sambo', 'code': 'FRM-0003', 'phone': '08030000003'},
    ];

    Future<Set<int>> pumpPicker(
      WidgetTester tester, {
      required List<dynamic> rows,
      Set<int> selected = const {},
    }) async {
      var current = {...selected};

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: StatefulBuilder(
            builder: (context, setState) => FarmerPicker(
              farmers: rows,
              selected: current,
              onChanged: (next) => setState(() => current = next),
            ),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      return current;
    }

    testWidgets('lists the farmers it was given', (tester) async {
      await pumpPicker(tester, rows: farmers);

      expect(find.text('Adamu Bobbo'), findsOneWidget);
      expect(find.text('Umaru Sambo'), findsOneWidget);
      expect(find.text('0 selected'), findsOneWidget);
    });

    testWidgets('ticking a farmer reports them to the form', (tester) async {
      await pumpPicker(tester, rows: farmers);

      await tester.tap(find.text('Asabe Ardo'));
      await tester.pumpAndSettle();

      expect(find.text('1 selected'), findsOneWidget);
    });

    testWidgets('search narrows by name, code and phone', (tester) async {
      await pumpPicker(tester, rows: farmers);

      await tester.enterText(find.byType(TextField), 'FRM-0002');
      await tester.pumpAndSettle();

      expect(find.text('Asabe Ardo'), findsOneWidget);
      expect(find.text('Adamu Bobbo'), findsNothing);
    });

    testWidgets('an empty register says the visit is still recordable', (tester) async {
      await pumpPicker(tester, rows: const []);

      // The message matters: an agent who has never had signal for this
      // community must not conclude the form is broken.
      expect(find.textContaining('still save the visit'), findsOneWidget);
    });
  });
}
