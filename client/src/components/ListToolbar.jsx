import { useState } from 'react';
import {
  Box, Checkbox, Divider, IconButton, ListItemIcon, ListItemText, Menu, MenuItem,
  Tooltip, Typography,
} from '@mui/material';
import ArrowDropDownIcon from '@mui/icons-material/ArrowDropDown';
import RefreshIcon from '@mui/icons-material/Refresh';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import ReportGmailerrorredIcon from '@mui/icons-material/ReportGmailerrorred';
import MarkEmailReadOutlinedIcon from '@mui/icons-material/MarkEmailReadOutlined';
import MarkEmailUnreadOutlinedIcon from '@mui/icons-material/MarkEmailUnreadOutlined';
import LabelOutlinedIcon from '@mui/icons-material/LabelOutlined';
import RestoreFromTrashIcon from '@mui/icons-material/RestoreFromTrash';
import KeyboardArrowLeftIcon from '@mui/icons-material/KeyboardArrowLeft';
import KeyboardArrowRightIcon from '@mui/icons-material/KeyboardArrowRight';
import DeleteForeverIcon from '@mui/icons-material/DeleteForever';
import { useMail } from '../state/MailContext';

export default function ListToolbar() {
  const {
    threads, selected, selectAll, selectMany, selectionTarget, actions, refresh, paging, goToPage,
    label, labels,
  } = useMail();
  const [selectMenu, setSelectMenu] = useState(null);
  const [moreMenu, setMoreMenu] = useState(null);
  const [labelMenu, setLabelMenu] = useState(null);

  const hasSelection = selected.size > 0;
  const target = selectionTarget;
  const allSelected = threads.length > 0 && selected.size === threads.length;

  const applyToSelection = (fn) => () => {
    fn(target);
    selectAll(false);
  };

  const selectWhere = (predicate) => {
    selectMany(predicate);
    setSelectMenu(null);
  };

  return (
    <Box
      sx={{
        display: 'flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.5, minHeight: 48,
        borderBottom: '1px solid', borderColor: 'divider', flexShrink: 0,
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center' }}>
        <Checkbox
          size="small"
          checked={allSelected}
          indeterminate={hasSelection && !allSelected}
          onChange={(event) => selectAll(event.target.checked)}
          inputProps={{ 'aria-label': 'Pilih semua percakapan' }}
        />
        <IconButton size="small" onClick={(event) => setSelectMenu(event.currentTarget)}>
          <ArrowDropDownIcon fontSize="small" />
        </IconButton>
      </Box>

      {hasSelection ? (
        <>
          {label !== 'trash' && (
            <Tooltip title="Arsipkan">
              <IconButton onClick={applyToSelection(actions.archive)}><ArchiveOutlinedIcon /></IconButton>
            </Tooltip>
          )}
          {label !== 'spam' ? (
            <Tooltip title="Laporkan spam">
              <IconButton onClick={applyToSelection(actions.spam)}><ReportGmailerrorredIcon /></IconButton>
            </Tooltip>
          ) : (
            <Tooltip title="Bukan spam">
              <IconButton onClick={applyToSelection(actions.notSpam)}><MarkEmailReadOutlinedIcon /></IconButton>
            </Tooltip>
          )}
          {label === 'trash' ? (
            <>
              <Tooltip title="Pulihkan">
                <IconButton onClick={applyToSelection(actions.restore)}><RestoreFromTrashIcon /></IconButton>
              </Tooltip>
              <Tooltip title="Hapus permanen">
                <IconButton onClick={applyToSelection(actions.deleteForever)}><DeleteForeverIcon /></IconButton>
              </Tooltip>
            </>
          ) : (
            <Tooltip title="Pindahkan ke sampah">
              <IconButton onClick={applyToSelection(actions.trash)}><DeleteOutlineIcon /></IconButton>
            </Tooltip>
          )}

          <Divider orientation="vertical" flexItem sx={{ mx: 0.5, my: 1 }} />

          <Tooltip title="Tandai sudah dibaca">
            <IconButton onClick={() => { actions.read(target, true); selectAll(false); }}>
              <MarkEmailReadOutlinedIcon />
            </IconButton>
          </Tooltip>
          <Tooltip title="Tandai belum dibaca">
            <IconButton onClick={() => { actions.read(target, false); selectAll(false); }}>
              <MarkEmailUnreadOutlinedIcon />
            </IconButton>
          </Tooltip>
          <Tooltip title="Beri label">
            <IconButton onClick={(event) => setLabelMenu(event.currentTarget)}><LabelOutlinedIcon /></IconButton>
          </Tooltip>

          <Typography variant="body2" color="text.secondary" sx={{ ml: 1 }}>
            {selected.size} dipilih
          </Typography>
        </>
      ) : (
        <>
          <Tooltip title="Muat ulang">
            <IconButton onClick={refresh}><RefreshIcon /></IconButton>
          </Tooltip>
          <Tooltip title="Lainnya">
            <IconButton onClick={(event) => setMoreMenu(event.currentTarget)}><MoreVertIcon /></IconButton>
          </Tooltip>
        </>
      )}

      <Box sx={{ ml: 'auto', display: 'flex', alignItems: 'center', gap: 0.5 }}>
        <Typography variant="body2" color="text.secondary" sx={{ display: { xs: 'none', sm: 'block' } }}>
          {paging.total === 0 ? '0' : `${paging.from}–${paging.to} dari ${paging.total.toLocaleString('id-ID')}`}
        </Typography>
        <Tooltip title="Lebih baru">
          <span>
            <IconButton disabled={paging.page <= 1} onClick={() => goToPage(paging.page - 1)}>
              <KeyboardArrowLeftIcon />
            </IconButton>
          </span>
        </Tooltip>
        <Tooltip title="Lebih lama">
          <span>
            <IconButton disabled={paging.page >= paging.pages} onClick={() => goToPage(paging.page + 1)}>
              <KeyboardArrowRightIcon />
            </IconButton>
          </span>
        </Tooltip>
      </Box>

      <Menu anchorEl={selectMenu} open={Boolean(selectMenu)} onClose={() => setSelectMenu(null)}>
        <MenuItem onClick={() => { selectAll(true); setSelectMenu(null); }}>Semua</MenuItem>
        <MenuItem onClick={() => { selectAll(false); setSelectMenu(null); }}>Tidak ada</MenuItem>
        <MenuItem onClick={() => selectWhere((thread) => !thread.is_read)}>Belum dibaca</MenuItem>
        <MenuItem onClick={() => selectWhere((thread) => thread.is_read)}>Sudah dibaca</MenuItem>
        <MenuItem onClick={() => selectWhere((thread) => thread.is_starred)}>Berbintang</MenuItem>
      </Menu>

      <Menu anchorEl={moreMenu} open={Boolean(moreMenu)} onClose={() => setMoreMenu(null)}>
        <MenuItem
          onClick={() => { actions.read({ threadKeys: threads.map((t) => t.thread_key) }, true); setMoreMenu(null); }}
        >
          <ListItemIcon><MarkEmailReadOutlinedIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Tandai semua sudah dibaca</ListItemText>
        </MenuItem>
        <MenuItem onClick={() => { refresh(); setMoreMenu(null); }}>
          <ListItemIcon><RefreshIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Muat ulang</ListItemText>
        </MenuItem>
      </Menu>

      <Menu anchorEl={labelMenu} open={Boolean(labelMenu)} onClose={() => setLabelMenu(null)}>
        {labels.filter((item) => item.type === 'user').map((item) => (
          <MenuItem
            key={item.id}
            onClick={() => { actions.addLabel(target, item.id); setLabelMenu(null); selectAll(false); }}
          >
            <ListItemIcon>
              <LabelOutlinedIcon fontSize="small" sx={{ color: item.color }} />
            </ListItemIcon>
            <ListItemText>{item.name}</ListItemText>
          </MenuItem>
        ))}
      </Menu>
    </Box>
  );
}
