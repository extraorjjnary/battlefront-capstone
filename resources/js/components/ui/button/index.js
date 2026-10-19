import { cva } from "class-variance-authority";
export { default as Button } from "./Button.vue";
export const buttonVariants = cva("inline-flex items-center justify-center gap-2 max-w-full whitespace-nowrap max-md:min-h-11 max-md:wrap-anywhere max-md:whitespace-normal rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive", {
    variants: {
        variant: {
            default: "bg-primary text-primary-foreground hover:bg-primary/90",
            destructive: "bg-destructive-action text-destructive-action-foreground hover:bg-destructive-action/90 focus-visible:ring-destructive",
            "destructive-outline": "border border-destructive-action/50 bg-destructive-action/5 text-destructive-action hover:border-destructive-action hover:bg-destructive-action/10 focus-visible:border-destructive focus-visible:ring-destructive dark:border-destructive/50 dark:bg-destructive/10 dark:text-destructive dark:hover:border-destructive dark:hover:bg-destructive/20",
            "destructive-solid": "bg-destructive-action text-destructive-action-foreground hover:bg-destructive-action/90 focus-visible:ring-destructive",
            outline: "border border-input bg-card shadow-xs hover:bg-accent hover:text-accent-foreground",
            secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
            ghost: "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
            link: "text-primary-ink underline-offset-4 hover:underline",
        },
        size: {
            "default": "h-9 px-4 py-2 has-[>svg]:px-3",
            "sm": "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
            "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
            "icon": "size-9 max-md:min-w-11",
            "icon-sm": "size-8 max-md:min-w-11",
            "icon-lg": "size-10 max-md:min-w-11",
        },
    },
    defaultVariants: {
        variant: "default",
        size: "default",
    },
});
