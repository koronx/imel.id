class User {
  final int id;
  final String email;
  final String fullName;
  final String? secondaryEmail;
  final int quotaBytes;
  final int quotaUsed;

  User({
    required this.id,
    required this.email,
    required this.fullName,
    this.secondaryEmail,
    this.quotaBytes = 1073741824, // 1GB default
    this.quotaUsed = 0,
  });

  double get quotaPercentage => quotaBytes > 0 ? (quotaUsed / quotaBytes) * 100 : 0;
  
  String get quotaUsedFormatted {
    final mb = quotaUsed / (1024 * 1024);
    if (mb < 1024) {
      return '${mb.toStringAsFixed(1)} MB';
    }
    return '${(mb / 1024).toStringAsFixed(2)} GB';
  }
  
  String get quotaTotalFormatted {
    final gb = quotaBytes / (1024 * 1024 * 1024);
    return '${gb.toStringAsFixed(0)} GB';
  }

  factory User.fromJson(Map<String, dynamic> json) {
    return User(
      id: json['id'],
      email: json['email'],
      fullName: json['full_name'],
      secondaryEmail: json['secondary_email'],
      quotaBytes: json['quota_bytes'] ?? 1073741824,
      quotaUsed: json['quota_used'] ?? 0,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'email': email,
      'full_name': fullName,
      'secondary_email': secondaryEmail,
      'quota_bytes': quotaBytes,
      'quota_used': quotaUsed,
    };
  }
}
