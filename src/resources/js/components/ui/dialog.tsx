import * as DialogPrimitive from "@radix-ui/react-dialog"
import { XIcon } from "lucide-react"
import * as React from "react"

import { useT } from "@/lib/i18n"
import { cn } from "@/lib/utils"

function Dialog({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 z-40 bg-overlay",
        className
      )}
      {...props}
    />
  )
}

const dialogSizes = {
  sm: "sm:max-w-[400px]",
  md: "sm:max-w-[560px]",
  lg: "sm:max-w-[800px]",
} as const

function DialogContent({
  className,
  children,
  size = "md",
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  size?: keyof typeof dialogSizes
}) {
  const t = useT()

  return (
    <DialogPortal data-slot="dialog-portal">
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed inset-0 z-40 m-auto grid h-fit max-h-[calc(100%-2rem)] w-full max-w-[calc(100%-2rem)] gap-4 overflow-y-auto rounded-lg border border-stroke-weak bg-raised p-5 text-strong shadow-raised md:p-6",
          dialogSizes[size],
          className
        )}
        {...props}
      >
        {children}
        <DialogPrimitive.Close className="absolute end-3 top-3 inline-flex size-9 items-center justify-center rounded-md text-weak hover:bg-hover hover:text-strong [&_svg]:size-5 [&_svg]:shrink-0">
          <XIcon />
          <span className="sr-only">{t("ui.close")}</span>
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-1 pe-8 text-start", className)}
      {...props}
    />
  )
}

function DialogFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end [&>button]:w-full sm:[&>button]:w-auto",
        className
      )}
      {...props}
    />
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn("text-subtitle text-strong", className)}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("text-body text-weak", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
