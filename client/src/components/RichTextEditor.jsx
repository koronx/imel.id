import { useEffect, useRef } from 'react';
import { Box, Divider, IconButton, Tooltip } from '@mui/material';
import FormatBoldIcon from '@mui/icons-material/FormatBold';
import FormatItalicIcon from '@mui/icons-material/FormatItalic';
import FormatUnderlinedIcon from '@mui/icons-material/FormatUnderlined';
import FormatListBulletedIcon from '@mui/icons-material/FormatListBulleted';
import FormatListNumberedIcon from '@mui/icons-material/FormatListNumbered';
import FormatQuoteIcon from '@mui/icons-material/FormatQuote';
import LinkIcon from '@mui/icons-material/Link';
import FormatClearIcon from '@mui/icons-material/FormatClear';
import StrikethroughSIcon from '@mui/icons-material/StrikethroughS';

const COMMANDS = [
  { icon: FormatBoldIcon, command: 'bold', title: 'Tebal (Ctrl+B)' },
  { icon: FormatItalicIcon, command: 'italic', title: 'Miring (Ctrl+I)' },
  { icon: FormatUnderlinedIcon, command: 'underline', title: 'Garis bawah (Ctrl+U)' },
  { icon: StrikethroughSIcon, command: 'strikeThrough', title: 'Coret' },
  { divider: true },
  { icon: FormatListBulletedIcon, command: 'insertUnorderedList', title: 'Daftar berpoin' },
  { icon: FormatListNumberedIcon, command: 'insertOrderedList', title: 'Daftar bernomor' },
  { icon: FormatQuoteIcon, command: 'formatBlock', value: 'blockquote', title: 'Kutipan' },
  { divider: true },
  { icon: FormatClearIcon, command: 'removeFormat', title: 'Hapus format' },
];

/**
 * Lightweight contentEditable editor. Formatting uses execCommand, which is
 * still the pragmatic choice for an email composer and keeps the bundle small.
 */
export default function RichTextEditor({ value, onChange, placeholder }) {
  const ref = useRef(null);

  // only write into the DOM when the value came from outside the editor
  useEffect(() => {
    if (ref.current && ref.current.innerHTML !== value) {
      ref.current.innerHTML = value || '';
    }
  }, [value]);

  const exec = (command, commandValue) => {
    ref.current?.focus();
    document.execCommand(command, false, commandValue);
    onChange(ref.current?.innerHTML || '');
  };

  const addLink = () => {
    const url = window.prompt('Masukkan URL tautan:');
    if (url) exec('createLink', url);
  };

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', minHeight: 0, flex: 1 }}>
      <Box
        ref={ref}
        contentEditable
        suppressContentEditableWarning
        data-placeholder={placeholder}
        onInput={(event) => onChange(event.currentTarget.innerHTML)}
        onPaste={(event) => {
          // paste as plain text so foreign styles do not leak into the message
          event.preventDefault();
          const text = event.clipboardData.getData('text/plain');
          document.execCommand('insertText', false, text);
        }}
        sx={{
          flex: 1, minHeight: 160, overflowY: 'auto', px: 2, py: 1.5, fontSize: 14,
          lineHeight: 1.6, outline: 'none', wordBreak: 'break-word',
          '&:empty:before': {
            content: 'attr(data-placeholder)',
            color: 'text.disabled',
          },
          '& blockquote': {
            borderLeft: '2px solid', borderColor: 'divider', margin: '8px 0',
            paddingLeft: 1.5, color: 'text.secondary',
          },
          '& img': { maxWidth: '100%' },
        }}
      />

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.25, px: 1, flexWrap: 'wrap' }}>
        {COMMANDS.map((item, index) => (item.divider ? (
          // eslint-disable-next-line react/no-array-index-key
          <Divider key={`divider-${index}`} orientation="vertical" flexItem sx={{ mx: 0.5, my: 1 }} />
        ) : (
          <Tooltip key={item.command + (item.value || '')} title={item.title}>
            <IconButton size="small" onMouseDown={(event) => event.preventDefault()} onClick={() => exec(item.command, item.value)}>
              <item.icon fontSize="small" />
            </IconButton>
          </Tooltip>
        )))}
        <Tooltip title="Sisipkan tautan">
          <IconButton size="small" onMouseDown={(event) => event.preventDefault()} onClick={addLink}>
            <LinkIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Box>
    </Box>
  );
}
