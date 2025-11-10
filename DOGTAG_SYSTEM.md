# Dog Tag Stencil Management System

## Overview
Created a dog tag stencil management system to organize users with tickets into groups of 12 for laser printing dog tags.

## Implementation Details

### 1. Database Changes

#### IDM Server (`idm.kaiserlan.at`)
- Added `dogTagGroup` field (nullable integer) to `User` entity
- Database schema updated with `doctrine:schema:update --force --complete`
- Field is exposed via IDM API with read/write permissions

#### KLMS Application (`kaiserlan.at`)
- Added `dogTagGroup` field to the mirror `User` entity
- Field syncs automatically via IDM API

### 2. New Controller
**Location:** `src/Controller/Admin/DogTagController.php`

**Routes:**
- `GET /admin/dogtags` - Main view (route name: `admin_dogtags_index`)
- `POST /admin/dogtags/assign/{uuid}` - Assign user to group (route name: `admin_dogtags_assign`)
- `POST /admin/dogtags/unassign/{uuid}` - Remove user from group (route name: `admin_dogtags_unassign`)

**Features:**
- Fetches all users with redeemed tickets
- Groups users by their `dogTagGroup` number (1-N)
- Supports auto-assignment (finds first group with <12 users)
- Supports manual group selection (dropdown with groups 1-20)
- Sorts users within groups by nickname

### 3. New Template
**Location:** `templates/admin/dogtag/index.html.twig`

**Display:**
- **Grouped Section:** Shows each group (1-N) with:
  - Group number and count (X / 12)
  - Table with: Nickname, Firstname, Lastname, Ticket status, Remove button
  - Empty slots shown as "Freier Platz" rows when group has <12 users
  - Clan tags displayed as badges
  
- **Unassigned Section:** Shows users with tickets not yet in a group:
  - Same user information table
  - "Auto-Zuweisen" button (assigns to first available group slot)
  - "Gruppe wählen" dropdown (manual selection of groups 1-20)

### 4. Updated Navigation
- Changed "DogTags" button in `templates/admin/payment/index.html.twig`
- Old: `href="?dogtags=1&unpaid=1"` (query params)
- New: `href="{{ path('admin_dogtags_index') }}"` (proper route)
- Icon changed from `fa-table` to `fa-tags`

## Usage

### Accessing the Feature
1. Navigate to Admin > Tickets (`/admin/payment`)
2. Click "DogTags" button
3. View shows existing groups and unassigned users

### Assigning Users
**Auto-Assignment:**
- Click "Auto-Zuweisen" button next to unassigned user
- System finds first group with <12 users or creates new group
- User is assigned and moved to that group

**Manual Assignment:**
- Click "Gruppe wählen" dropdown
- Select specific group number (1-20)
- User is assigned to that group (even if it already has 12 users)

### Removing Users
- Click red X button next to user in a group
- Confirms before removing
- User moves back to unassigned list

## Technical Notes

### IDM Integration
- User data stored in IDM, never locally in KLMS
- Uses `IdmManager` and `IdmRepository` pattern
- Changes persist via `$userRepo->persist($user)` and `$userRepo->flush()`

### Group Management Logic
- Groups are simply integers (1, 2, 3, ...)
- Each group should have max 12 users (for stencil printing)
- Auto-assign finds first group with <12 users
- No hard limit enforced - manual assignment allows overfilling
- Empty slots displayed visually in UI

### Permissions
- Requires `ROLE_ADMIN_PAYMENT` permission
- Same permission level as ticket management

## Future Enhancements (Optional)
- Add print-friendly view per group
- Export group data for laser printer
- Bulk assignment tools
- Group notes/labels
- Historical tracking of group assignments
