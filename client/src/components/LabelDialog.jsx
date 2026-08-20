import { useEffect, useState } from 'react';
import {
  Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField, Tooltip,
} from '@mui/material';
import { api } from '../api/client';
import { useMail } from '../state/MailContext';

const COLORS = [
  '#d93025', '#e37400', '#f4b400', '#0b8043', '#188038',
  '#1a73e8', '#3949ab', '#673ab7', '#8e24aa', '#5f6368',
];

export default function LabelDialog({ open, label, onClose }) {
  const { loadLabels, notify, openLabel } = useMail();
  const [name, setName] = useState('');
  const [color, setColor] = useState(COLORS[5]);
  const [saving, setSaving] = useState(false);
  const [fieldError, setFieldError] = useState('');

  useEffect(() => {
    if (!open) return;
    setName(label?.name || '');
    setColor(label?.color || COLORS[5]);
    setFieldError('');
  }, [open, label]);

  const save = async () => {
    if (!name.trim()) {
      setFieldError('Nama label wajib diisi');
      return;
    }
    setSaving(true);
    try {
      if (label?.id) await api.updateLabel(label.id, { name: name.trim(), color });
      else await api.createLabel({ name: name.trim(), color });
      await loadLabels();
      onClose();
    } catch (err) {
      setFieldError(err.fields?.name || err.message);
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    setSaving(true);
    try {
      await api.deleteLabel(label.id);
      await loadLabels();
      openLabel('inbox');
      onClose();
    } catch (err) {
      notify(err.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ fontSize: 18 }}>{label?.id ? 'Edit label' : 'Label baru'}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <TextField
            autoFocus
            size="small"
            label="Nama label"
            value={name}
            onChange={(event) => { setName(event.target.value); setFieldError(''); }}
            error={Boolean(fieldError)}
            helperText={fieldError}
            fullWidth
          />
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
            {COLORS.map((option) => (
              <Tooltip key={option} title={option}>
                <Box
                  onClick={() => setColor(option)}
                  sx={{
                    width: 28, height: 28, borderRadius: '50%', bgcolor: option, cursor: 'pointer',
                    outline: color === option ? '2px solid' : 'none',
                    outlineColor: 'text.primary', outlineOffset: 2,
                  }}
                />
              </Tooltip>
            ))}
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        {label?.id && (
          <Button color="error" onClick={remove} disabled={saving} sx={{ mr: 'auto' }}>
            Hapus
          </Button>
        )}
        <Button onClick={onClose}>Batal</Button>
        <Button variant="contained" onClick={save} disabled={saving}>Simpan</Button>
      </DialogActions>
    </Dialog>
  );
}
