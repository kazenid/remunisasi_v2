<template>
    <div class="card">
        <div class="flex items-center justify-between mb-6">
            <div class="font-semibold text-xl">Notifications</div>
            <div>
                <Button icon="pi pi-ellipsis-v" severity="secondary" variant="text" rounded />
            </div>
        </div>

        <ul class="p-0 m-0 list-none flex flex-col gap-4">
            <li class="flex items-start gap-3">
                <div class="w-10 h-10 flex items-center justify-center rounded-full bg-blue-100 dark:bg-blue-400/10 shrink-0">
                    <i class="pi pi-dollar text-blue-600 dark:text-blue-400"></i>
                </div>
                <div>
                    <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block">Richard Jones</span>
                    <span class="text-muted-color text-sm">has purchased a blue t-shirt for <span class="text-surface-900 dark:text-surface-0 font-medium">$79</span></span>
                    <span class="text-muted-color text-xs block mt-1">5 hours ago</span>
                </div>
            </li>
            <li class="flex items-start gap-3">
                <div class="w-10 h-10 flex items-center justify-center rounded-full bg-orange-100 dark:bg-orange-400/10 shrink-0">
                    <i class="pi pi-download text-orange-600 dark:text-orange-400"></i>
                </div>
                <div>
                    <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block">Your request</span>
                    <span class="text-muted-color text-sm">has been initiated.</span>
                    <span class="text-muted-color text-xs block mt-1">2 hours ago</span>
                </div>
            </li>
            <li class="flex items-start gap-3">
                <div class="w-10 h-10 flex items-center justify-center rounded-full bg-purple-100 dark:bg-purple-400/10 shrink-0">
                    <i class="pi pi-search text-purple-600 dark:text-purple-400"></i>
                </div>
                <div>
                    <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block">Your request</span>
                    <span class="text-muted-color text-sm">is in review. <span class="text-blue-500 cursor-pointer hover:underline">Click here</span> to see details.</span>
                    <span class="text-muted-color text-xs block mt-1">4 minutes ago</span>
                </div>
            </li>
            <li class="flex items-start gap-3">
                <div class="w-10 h-10 flex items-center justify-center rounded-full bg-cyan-100 dark:bg-cyan-400/10 shrink-0">
                    <i class="pi pi-check text-cyan-600 dark:text-cyan-400"></i>
                </div>
                <div>
                    <span class="font-medium text-surface-900 dark:text-surface-0 text-sm block">Delivery</span>
                    <span class="text-muted-color text-sm">has been completed successfully.</span>
                    <span class="text-muted-color text-xs block mt-1">1 day ago</span>
                </div>
            </li>
        </ul>
    </div>
</template>
