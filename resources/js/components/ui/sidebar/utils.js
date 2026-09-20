import { createContext } from "reka-ui";
export const SIDEBAR_COOKIE_PREFIX = "sidebar_state_";
export const SIDEBAR_COOKIE_MAX_AGE = 60 * 60 * 24 * 7;
export const SIDEBAR_WIDTH = "16rem";
export const SIDEBAR_WIDTH_MOBILE = "18rem";
export const SIDEBAR_WIDTH_ICON = "3rem";
export const SIDEBAR_KEYBOARD_SHORTCUT = "b";
export const getSidebarCookieName = (userId) => userId == null ? null : `${SIDEBAR_COOKIE_PREFIX}${userId}`;
export const [useSidebar, provideSidebarContext] = createContext("Sidebar");
