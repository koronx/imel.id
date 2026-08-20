import { useState } from 'react';
import {
  Button, Checkbox, Dialog, DialogActions, DialogContent, FormControlLabel,
  MenuItem, Stack, TextField,
} from '@mui/material';
import { useMail } from '../state/MailContext';

const EMPTY = {
  from: '', to: '', subject: '', words: '', exclude: '', filename: '',
  scope: 'all', hasAttachment: false, unreadOnly: false, newerThan: '',
};

/** Builds a Gmail-style operator query from the advanced search form. */
function buildQuery(form) {
  const parts = [];
  if (form.from) parts.push(`from:${form.from}`);
  if (form.to) parts.push(`to:${form.to}`);
  if (form.subject) parts.push(`subject:${form.subject}`);
  if (form.filename) parts.push(`filename:${form.filename}`);
  if (form.scope && form.scope !== 'all') parts.push(`in:${form.scope}`);
  if (form.hasAttachment) parts.push('has:attachment');
  if (form.unreadOnly) parts.push('is:unread');
  if (form.newerThan) parts.push(`newer_than:${form.newerThan}`);
  if (form.words) parts.push(form.words);
  if (form.exclude) parts.push(...form.exclude.split(/\s+/).filter(Boolean).map((word) => `-${word}`));
  return parts.join(' ');
}

export default function SearchFilterDialog({ open, onClose, onApply }) {
  const { labels } = useMail();
  const [form, setForm] = useState(EMPTY);

  const set = (field) => (event) => setForm({
    ...form,
    [field]: event.target.type === 'checkbox' ? event.target.checked : event.target.value,
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <TextField label="Dari" size="small" value={form.from} onChange={set('from')} fullWidth />
          <TextField label="Kepada" size="small" value={form.to} onChange={set('to')} fullWidth />
          <TextField label="Subjek" size="small" value={form.subject} onChange={set('subject')} fullWidth />
          <TextField label="Berisi kata" size="small" value={form.words} onChange={set('words')} fullWidth />
          <TextField
            label="Tidak berisi"
            size="small"
            value={form.exclude}
            onChange={set('exclude')}
            fullWidth
            helperText="Pisahkan dengan spasi"
          />
          <TextField label="Nama file lampiran" size="small" value={form.filename} onChange={set('filename')} fullWidth />

          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField select label="Telusuri" size="small" value={form.scope} onChange={set('scope')} sx={{ flex: 1 }}>
              <MenuItem value="all">Semua email</MenuItem>
              {labels.filter((label) => label.type !== 'category').map((label) => (
                <MenuItem key={label.slug} value={label.slug}>{label.name}</MenuItem>
              ))}
            </TextField>
            <TextField select label="Rentang waktu" size="small" value={form.newerThan} onChange={set('newerThan')} sx={{ flex: 1 }}>
              <MenuItem value="">Kapan saja</MenuItem>
              <MenuItem value="1d">1 hari terakhir</MenuItem>
              <MenuItem value="7d">7 hari terakhir</MenuItem>
              <MenuItem value="1m">1 bulan terakhir</MenuItem>
              <MenuItem value="1y">1 tahun terakhir</MenuItem>
            </TextField>
          </Stack>

          <Stack direction="row" spacing={2}>
            <FormControlLabel
              control={<Checkbox checked={form.hasAttachment} onChange={set('hasAttachment')} />}
              label="Ada lampiran"
            />
            <FormControlLabel
              control={<Checkbox checked={form.unreadOnly} onChange={set('unreadOnly')} />}
              label="Belum dibaca"
            />
          </Stack>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={() => setForm(EMPTY)}>Reset</Button>
        <Button onClick={onClose}>Batal</Button>
        <Button variant="contained" onClick={() => onApply(buildQuery(form))}>Telusuri</Button>
      </DialogActions>
    </Dialog>
  );
}
