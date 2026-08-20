import { useEffect, useRef, useState } from 'react';
import { Box } from '@mui/material';
import { useTheme } from '@mui/material/styles';

/**
 * Message HTML is rendered inside a sandboxed iframe: no scripts, no access to
 * the parent document, and the sender's own CSS cannot leak into the app.
 */
export default function MessageBody({ html, text }) {
  const theme = useTheme();
  const frameRef = useRef(null);
  const [height, setHeight] = useState(120);

  const content = html && html.trim()
    ? html
    : `<pre style="white-space:pre-wrap;word-break:break-word;font-family:inherit;margin:0">${
      String(text || '').replace(/[<>&]/g, (char) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[char]))
    }</pre>`;

  const document_ = `<!doctype html>
<html><head><meta charset="utf-8"><base target="_blank">
<style>
  :root { color-scheme: ${theme.palette.mode}; }
  body {
    margin: 0; padding: 0;
    font-family: Roboto, Arial, sans-serif; font-size: 14px; line-height: 1.5;
    color: ${theme.palette.text.primary};
    background: transparent;
    word-break: break-word;
  }
  img { max-width: 100%; height: auto; }
  a { color: ${theme.palette.primary.main}; }
  table { max-width: 100%; }
  blockquote, .imel-quote {
    border-left: 2px solid ${theme.palette.divider};
    margin: 8px 0; padding-left: 12px; color: ${theme.palette.text.secondary};
  }
</style></head>
<body>${content}</body></html>`;

  useEffect(() => {
    const frame = frameRef.current;
    if (!frame) return undefined;

    const resize = () => {
      try {
        const body = frame.contentDocument?.body;
        if (body) setHeight(Math.max(60, body.scrollHeight + 16));
      } catch {
        /* cross-origin frames cannot be measured; keep the default height */
      }
    };

    frame.addEventListener('load', resize);
    const timer = setInterval(resize, 500);
    const stop = setTimeout(() => clearInterval(timer), 4000);

    return () => {
      frame.removeEventListener('load', resize);
      clearInterval(timer);
      clearTimeout(stop);
    };
  }, [document_]);

  return (
    <Box
      component="iframe"
      ref={frameRef}
      title="Isi pesan"
      srcDoc={document_}
      sandbox="allow-popups allow-popups-to-escape-sandbox"
      sx={{ width: '100%', border: 0, height, display: 'block' }}
    />
  );
}
