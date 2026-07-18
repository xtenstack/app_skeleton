import { PropsWithChildren, SyntheticEvent, useState } from 'react';
import { useNavigate } from 'react-router';
import {
  Box,
  Button,
  Divider,
  Link,
  ListItemIcon,
  MenuItem,
  MenuItemProps,
  SnackbarCloseReason,
  Stack,
  Switch,
  SxProps,
  Typography,
  listClasses,
  listItemIconClasses,
  paperClasses,
} from '@mui/material';
import Menu from '@mui/material/Menu';
import { useAuth } from 'providers/AuthProvider';
import paths from 'routes/paths';
import IconifyIcon from 'components/base/IconifyIcon';
import StatusAvatar from 'components/base/StatusAvatar';
import ProSnackbar from './ProSnackbar';

interface ProfileMenuItemProps extends MenuItemProps {
  icon: string;
  href?: string;
  sx?: SxProps;
}

const ProfileMenu = () => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const displayName = user?.email ?? 'Guest';

  const [snackbarOpen, setSnackbarOpen] = useState(false);
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null);

  const open = Boolean(anchorEl);
  const handleClick = (event: React.MouseEvent<HTMLElement>) => setAnchorEl(event.currentTarget);
  const handleClose = () => setAnchorEl(null);

  const handleSnackbarOpen = () => setSnackbarOpen(true);
  const handleSnackbarClose = (_event: SyntheticEvent, reason?: SnackbarCloseReason) => {
    if (reason === 'clickaway') return;
    setSnackbarOpen(false);
  };

  const handleSignOut = async () => {
    handleClose();
    await logout();
    navigate(paths.login);
  };

  const menuButton = (
    <Button
      color="neutral"
      variant="text"
      shape="circle"
      onClick={handleClick}
      sx={{
        height: 44,
        width: 44,
      }}
    >
      <StatusAvatar
        alt={displayName}
        status="online"
        sx={{
          width: 40,
          height: 40,
          border: 2,
          borderColor: 'background.paper',
        }}
      >
        {displayName.charAt(0).toUpperCase()}
      </StatusAvatar>
    </Button>
  );
  return (
    <>
      {menuButton}
      <Menu
        anchorEl={anchorEl}
        id="account-menu"
        open={open}
        onClose={handleClose}
        transformOrigin={{
          horizontal: 'right',
          vertical: 'top',
        }}
        anchorOrigin={{
          horizontal: 'right',
          vertical: 'bottom',
        }}
        sx={{
          [`& .${paperClasses.root}`]: { minWidth: 320 },
          [`& .${listClasses.root}`]: { py: 0 },
        }}
      >
        <Stack
          sx={{
            alignItems: 'center',
            gap: 2,
            px: 3,
            py: 2,
          }}
        >
          <StatusAvatar status="online" alt={displayName} sx={{ width: 48, height: 48 }}>
            {displayName.charAt(0).toUpperCase()}
          </StatusAvatar>
          <Box>
            <Typography
              variant="subtitle1"
              sx={{
                fontWeight: 700,
                mb: 0.5,
              }}
            >
              {displayName}
            </Typography>
          </Box>
        </Stack>
        <Divider />
        <Box sx={{ py: 1 }}>
          <ProfileMenuItem icon="material-symbols:accessible-forward-rounded" onClick={handleClose}>
            Accessibility
          </ProfileMenuItem>

          <ProfileMenuItem icon="material-symbols:settings-outline-rounded" onClick={handleClose}>
            Preferences
          </ProfileMenuItem>

          <ProfileMenuItem
            onClick={handleSnackbarOpen}
            icon="material-symbols:dark-mode-outline-rounded"
          >
            Dark mode
            <Switch checked={false} sx={{ ml: 'auto' }} />
          </ProfileMenuItem>
        </Box>
        <Divider />
        <Box sx={{ py: 1 }}>
          <ProfileMenuItem
            icon="material-symbols:manage-accounts-outline-rounded"
            onClick={handleClose}
            href="#!"
          >
            Account Settings
          </ProfileMenuItem>
          <ProfileMenuItem
            icon="material-symbols:question-mark-rounded"
            onClick={handleClose}
            href="#!"
          >
            Help Center
          </ProfileMenuItem>
        </Box>
        <Divider />
        <Box sx={{ py: 1 }}>
          {user ? (
            <ProfileMenuItem onClick={handleSignOut} icon="material-symbols:logout-rounded">
              Sign Out
            </ProfileMenuItem>
          ) : (
            <ProfileMenuItem href={paths.login} icon="material-symbols:login-rounded">
              Sign In
            </ProfileMenuItem>
          )}
        </Box>
      </Menu>
      <ProSnackbar open={snackbarOpen} onClose={handleSnackbarClose} />
    </>
  );
};

const ProfileMenuItem = ({
  icon,
  onClick,
  children,
  href,
  sx,
}: PropsWithChildren<ProfileMenuItemProps>) => {
  const linkProps = href ? { component: Link, href, underline: 'none' } : {};
  return (
    <MenuItem onClick={onClick} {...linkProps} sx={{ gap: 1, ...sx }}>
      <ListItemIcon
        sx={{
          [`&.${listItemIconClasses.root}`]: { minWidth: 'unset !important' },
        }}
      >
        <IconifyIcon icon={icon} sx={{ color: 'text.secondary' }} />
      </ListItemIcon>
      {children}
    </MenuItem>
  );
};

export default ProfileMenu;
