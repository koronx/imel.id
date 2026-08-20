import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Box, Button, Chip, CircularProgress, IconButton, Paper, TextField, Tooltip, Typography,
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import RemoveIcon from '@mui/icons-material/Remove';
import OpenInFullIcon from '@mui/icons-material/OpenInFull';
import CloseFullscreenIcon from '@mui/icons-material/CloseFullscreen';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import SendIcon from '@mui/icons-material/Send';
import { api } from '../api/client';
import { useMail } from '../state/MailContext';
import { useAuth } from '../state/AuthContext';
import { formatBytes } from '../utils/format';
import RecipientInput from './RecipientInput';
import RichTextEditor from './RichTextEditor';

const AUTOSAVE_MS = 4000;

export default function ComposeWindow({ composer }) {
  const { closeCompose, updateCompose, sendMessage, notify, refresh } = useMail();
  const { user, settings } = useAuth();

  const signature = settings.signature_enabled && settings.signature
    ? `<br><br>--<br>${settings.signature.replace(/\n/g, '<br>')}`
    : '';

  const [to, setTo] = useState(composer.to || []);
  const [cc, setCc] = useState(composer.cc || []);
  const [bcc, setBcc] = useState(composer.bcc || []);
  const [showCc, setShowCc] = useState(Boolean(composer.showCc || composer.cc?.length));
  const [showBcc, setShowBcc] = useState(Boolean(composer.bcc?.length));
  const [subject, setSubject] = useState(composer.subject || '');
  const [html, setHtml] = useState((composer.html || '') + (composer.html ? '' : signature));
  const [attachments, setAttachments] = useState(composer.attachments || []);
  const [uploading, setUploading] = useState(false);
  const [draftId, setDraftId] = useState(composer.draftId || 0);
  const [savedAt, setSavedAt] = useState('');
  const fileRef = useRef(null);
  const dirtyRef = useRef(false);
  const stateRef = useRef({});

  stateRef.current = { to, cc, bcc, subject, html, attachments, draftId };

  const markDirty = () => { dirtyRef.current = true; };

  const saveDraft = useCallback(async () => {
    const state = stateRef.current;
    const empty = !state.to.length && !state.subject && !state.html.replace(/<[^>]*>/g, '').trim();
    if (empty) return;

    try {
      const data = await api.saveDraft({
        draft_id: state.draftId || undefined,
        to: state.to,
        cc: state.cc,
        bcc: state.bcc,
        subject: state.subject,
        body_html: state.html,
        attachment_ids: state.attachments.filter((item) => !item.saved).map((item) => item.id),
        thread_key: composer.threadKey,
        in_reply_to: composer.inReplyTo,
      });
      setDraftId(data.draft.id);
      setAttachments(data.draft.attachments?.length
        ? data.draft.attachments.map((item) => ({ ...item, saved: true }))
        : state.attachments);
      setSavedAt(new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }));
      dirtyRef.current = false;
    } catch (err) {
      notify(`Draf gagal disimpan: ${err.message}`);
    }
  }, [composer.threadKey, composer.inReplyTo, notify]);

  // periodic autosave while the window is open
  useEffect(() => {
    const timer = setInterval(() => {
      if (dirtyRef.current) saveDraft();
    }, AUTOSAVE_MS);
    return () => clearInterval(timer);
  }, [saveDraft]);

  const upload = async (event) => {
    const files = [...(event.target.files || [])];
    if (!files.length) return;
    setUploading(true);
    try {
      for (const file of files) {
        // eslint-disable-next-line no-await-in-loop
        const data = await api.uploadAttachment(file);
        setAttachments((current) => [...current, ...data.attachments]);
      }
      markDirty();
    } catch (err) {
      notify(err.message);
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeAttachment = async (attachment) => {
    setAttachments((current) => current.filter((item) => item.id !== attachment.id));
    if (!attachment.saved) {
      try {
        await api.deleteAttachment(attachment.id);
      } catch {
        /* the staging row disappears with the draft anyway */
      }
    }
  };

  const submit = () => {
    if (!to.length && !cc.length && !bcc.length) {
      notify('Tambahkan minimal satu penerima');
      return;
    }
    sendMessage({
      to, cc, bcc, subject, html,
      attachmentIds: attachments.map((item) => item.id),
      inReplyTo: composer.inReplyTo,
      references: composer.references,
      threadKey: composer.threadKey,
      draftId: draftId || undefined,
    });
    closeCompose(composer.id);
  };

  const discard = async () => {
    if (draftId) {
      try {
        await api.deleteDraft(draftId);
        refresh();
      } catch (err) {
        notify(err.message);
      }
    }
    closeCompose(composer.id);
  };

  const close = async () => {
    if (dirtyRef.current) await saveDraft();
    closeCompose(composer.id);
    if (dirtyRef.current === false && draftId) refresh();
  };

  const { minimized, maximized } = composer;

  const size = maximized
    ? { width: 'min(1000px, 96vw)', height: 'min(84vh, 800px)' }
    : { width: 'min(520px, 96vw)', height: minimized ? 'auto' : 'min(560px, 78vh)' };

  return (
    <Paper
      elevation={8}
      sx={{
        ...size,
        display: 'flex', flexDirection: 'column',
        borderRadius: '12px 12px 0 0', overflow: 'hidden',
        boxShadow: '0 8px 24px rgba(60,64,67,.35)',
      }}
    >
      <Box
        onClick={() => minimized && updateCompose(composer.id, { minimized: false })}
        sx={{
          display: 'flex', alignItems: 'center', gap: 0.5, px: 2, py: 1,
          bgcolor: (theme) => (theme.palette.mode === 'dark' ? '#3c4043' : '#f2f6fc'),
          cursor: minimized ? 'pointer' : 'default',
        }}
      >
        <Typography sx={{ flex: 1, fontSize: 14, fontWeight: 500 }} noWrap>
          {subject || 'Pesan Baru'}
        </Typography>
        <Tooltip title={minimized ? 'Buka' : 'Kecilkan'}>
          <IconButton size="small" onClick={() => updateCompose(composer.id, { minimized: !minimized })}>
            <RemoveIcon fontSize="small" />
          </IconButton>
        </Tooltip>
        <Tooltip title={maximized ? 'Keluar layar penuh' : 'Layar penuh'}>
          <IconButton
            size="small"
            onClick={() => updateCompose(composer.id, { maximized: !maximized, minimized: false })}
          >
            {maximized ? <CloseFullscreenIcon fontSize="small" /> : <OpenInFullIcon fontSize="small" />}
          </IconButton>
        </Tooltip>
        <Tooltip title="Simpan & tutup">
          <IconButton size="small" onClick={close}><CloseIcon fontSize="small" /></IconButton>
        </Tooltip>
      </Box>

      {!minimized && (
        <>
          <Box sx={{ px: 2, pt: 1 }}>
            <RecipientInput
              label="Penerima"
              value={to}
              onChange={(next) => { setTo(next); markDirty(); }}
              autoFocus={!composer.restored}
              endAdornment={(
                <Box sx={{ display: 'flex', gap: 0.5, mr: 1 }}>
                  {!showCc && (
                    <Button size="small" sx={{ minWidth: 0, px: 0.5 }} onClick={() => setShowCc(true)}>Cc</Button>
                  )}
                  {!showBcc && (
                    <Button size="small" sx={{ minWidth: 0, px: 0.5 }} onClick={() => setShowBcc(true)}>Bcc</Button>
                  )}
                </Box>
              )}
            />
            {showCc && (
              <Box sx={{ mt: 0.5 }}>
                <RecipientInput label="Cc" value={cc} onChange={(next) => { setCc(next); markDirty(); }} />
              </Box>
            )}
            {showBcc && (
              <Box sx={{ mt: 0.5 }}>
                <RecipientInput label="Bcc" value={bcc} onChange={(next) => { setBcc(next); markDirty(); }} />
              </Box>
            )}
            <TextField
              variant="standard"
              placeholder="Subjek"
              value={subject}
              onChange={(event) => { setSubject(event.target.value); markDirty(); }}
              fullWidth
              sx={{ mt: 0.5, '& .MuiInput-root': { fontSize: 14 } }}
            />
          </Box>

          <RichTextEditor
            value={html}
            onChange={(next) => { setHtml(next); markDirty(); }}
            placeholder="Tulis pesan Anda..."
          />

          {attachments.length > 0 && (
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, px: 2, pb: 1 }}>
              {attachments.map((attachment) => (
                <Chip
                  key={attachment.id}
                  icon={<AttachFileIcon />}
                  label={`${attachment.filename} (${formatBytes(attachment.size_bytes)})`}
                  onDelete={() => removeAttachment(attachment)}
                  variant="outlined"
                  sx={{ maxWidth: 240, height: 28 }}
                />
              ))}
            </Box>
          )}

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 2, py: 1.5 }}>
            <Button
              variant="contained"
              endIcon={<SendIcon />}
              onClick={submit}
              sx={{ borderRadius: 6, px: 3 }}
            >
              Kirim
            </Button>
            <input ref={fileRef} type="file" hidden multiple onChange={upload} />
            <Tooltip title="Lampirkan berkas">
              <span>
                <IconButton size="small" disabled={uploading} onClick={() => fileRef.current?.click()}>
                  {uploading ? <CircularProgress size={18} /> : <AttachFileIcon fontSize="small" />}
                </IconButton>
              </span>
            </Tooltip>

            <Typography variant="caption" color="text.secondary" sx={{ ml: 'auto', mr: 1 }}>
              {savedAt ? `Draf disimpan ${savedAt}` : `Dari ${user?.email}`}
            </Typography>

            <Tooltip title="Buang draf">
              <IconButton size="small" onClick={discard}><DeleteOutlineIcon fontSize="small" /></IconButton>
            </Tooltip>
          </Box>
        </>
      )}
    </Paper>
  );
}
