import { useEffect, useMemo, useState } from 'react';
import { Autocomplete, Avatar, Box, Chip, TextField, Typography } from '@mui/material';
import { api } from '../api/client';
import { colorFor, initials } from '../utils/format';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Chip based address field with contact suggestions from the server. */
export default function RecipientInput({ label, value, onChange, autoFocus, endAdornment }) {
  const [input, setInput] = useState('');
  const [options, setOptions] = useState([]);

  useEffect(() => {
    let active = true;
    const timer = setTimeout(async () => {
      try {
        const data = await api.contacts(input);
        if (active) setOptions(data.contacts || []);
      } catch {
        if (active) setOptions([]);
      }
    }, 200);

    return () => { active = false; clearTimeout(timer); };
  }, [input]);

  const suggestions = useMemo(
    () => options.filter((option) => !value.includes(option.email)),
    [options, value],
  );

  return (
    <Autocomplete
      multiple
      freeSolo
      size="small"
      value={value}
      inputValue={input}
      options={suggestions}
      filterOptions={(list) => list}
      getOptionLabel={(option) => (typeof option === 'string' ? option : option.email)}
      isOptionEqualToValue={(option, current) => (option.email || option) === current}
      onInputChange={(event, next, reason) => {
        if (reason === 'input') setInput(next);
        if (reason === 'reset') setInput('');
      }}
      onChange={(event, next) => {
        const emails = next
          .map((item) => (typeof item === 'string' ? item.trim() : item.email))
          .filter((email) => EMAIL_RE.test(email));
        onChange([...new Set(emails)]);
        setInput('');
      }}
      renderTags={(tags, getTagProps) => tags.map((email, index) => (
        <Chip
          {...getTagProps({ index })}
          key={email}
          size="small"
          avatar={<Avatar sx={{ bgcolor: colorFor(email) }}>{initials(email)}</Avatar>}
          label={email}
          sx={{ borderRadius: 4, height: 26 }}
        />
      ))}
      renderOption={(props, option) => (
        <Box component="li" {...props} key={option.email} sx={{ gap: 1.5 }}>
          <Avatar sx={{ width: 28, height: 28, fontSize: 12, bgcolor: colorFor(option.email) }}>
            {initials(option.name || option.email)}
          </Avatar>
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="body2" noWrap>{option.name}</Typography>
            <Typography variant="caption" color="text.secondary" noWrap>{option.email}</Typography>
          </Box>
        </Box>
      )}
      renderInput={(params) => (
        <TextField
          {...params}
          variant="standard"
          placeholder={value.length ? '' : label}
          autoFocus={autoFocus}
          InputProps={{
            ...params.InputProps,
            disableUnderline: false,
            endAdornment: (
              <>
                {endAdornment}
                {params.InputProps.endAdornment}
              </>
            ),
          }}
          sx={{ '& .MuiInput-root': { fontSize: 14 } }}
        />
      )}
    />
  );
}
