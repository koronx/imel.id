class EmailMessage {
  final int id;
  final String sender;
  final String recipient;
  final String subject;
  final String body;
  final DateTime createdAt;
  final bool isRead;
  final bool hasAttachment;

  EmailMessage({
    required this.id,
    required this.sender,
    required this.recipient,
    required this.subject,
    required this.body,
    required this.createdAt,
    this.isRead = false,
    this.hasAttachment = false,
  });

  factory EmailMessage.fromJson(Map<String, dynamic> json) {
    return EmailMessage(
      id: json['id'],
      sender: json['sender'],
      recipient: json['recipient'],
      subject: json['subject'],
      body: json['body'],
      createdAt: DateTime.parse(json['created_at']),
      isRead: json['is_read'] ?? false,
      hasAttachment: json['has_attachment'] ?? false,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'sender': sender,
      'recipient': recipient,
      'subject': subject,
      'body': body,
      'created_at': createdAt.toIso8601String(),
      'is_read': isRead,
      'has_attachment': hasAttachment,
    };
  }
}
