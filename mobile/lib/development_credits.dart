import 'package:flutter/material.dart';

import 'app_identity.dart';

class DevelopmentCredits extends StatelessWidget {
  const DevelopmentCredits({super.key});

  @override
  Widget build(BuildContext context) => Text(
        'تطوير وإشراف الفريق الفني: محمد حمدان، يوسف حمدان ومصطفى فرحات',
        textAlign: TextAlign.center,
        textDirection: TextDirection.rtl,
        style: AppIdentity.body(11.5, color: AppIdentity.faint)
            .copyWith(height: 1.8),
      );
}
