class Attachment {
  final int id;
  final String filename;
  final String mimeType;
  final int size;

  Attachment({
    required this.id,
    required this.filename,
    required this.mimeType,
    required this.size,
  });

  factory Attachment.fromJson(Map<String, dynamic> json) {
    return Attachment(
      id: json['id'],
      filename: json['filename'],
      mimeType: json['mime_type'],
      size: json['size'],
    );
  }

  String get formattedSize {
    if (size < 1024) return '$size B';
    if (size < 1024 * 1024) return '${(size / 1024).toStringAsFixed(1)} KB';
    return '${(size / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}
