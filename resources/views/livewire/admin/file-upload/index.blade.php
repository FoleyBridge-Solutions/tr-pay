<div>
    <div class="mb-8">
        <flux:heading size="xl">File Upload</flux:heading>
        <flux:subheading>Upload files to the server</flux:subheading>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Upload Form --}}
        <div class="lg:col-span-2">
            <flux:card>
                <div class="p-6">
                    <div class="mb-6">
                        <flux:field>
                            <flux:label>Select File</flux:label>
                            <div
                                x-data="{ dragging: false }"
                                x-on:dragover.prevent="dragging = true"
                                x-on:dragleave.prevent="dragging = false"
                                x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change'))"
                                class="border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                                :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-300 dark:border-zinc-700'"
                            >
                                <input
                                    type="file"
                                    wire:model="uploadFile"
                                    x-ref="fileInput"
                                    class="hidden"
                                    id="file-upload"
                                />
                                <label for="file-upload" class="cursor-pointer">
                                    <flux:icon name="arrow-up-tray" class="w-10 h-10 mx-auto text-zinc-400 mb-2" />
                                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                                        Drop your file here or <span class="text-blue-600 underline">browse</span>
                                    </flux:text>
                                    <flux:text class="text-sm text-zinc-500 mt-1">Max file size: 50MB</flux:text>
                                </label>

                                @if($uploadFile)
                                    <div class="mt-4 p-3 bg-zinc-100 dark:bg-zinc-800 rounded-lg inline-flex items-center gap-2">
                                        <flux:icon name="document" class="w-5 h-5 text-zinc-500" />
                                        <span>{{ $uploadFile->getClientOriginalName() }}</span>
                                        <flux:button wire:click="$set('uploadFile', null)" size="sm" variant="ghost" icon="x-mark" />
                                    </div>
                                @endif
                            </div>
                            <flux:error name="uploadFile" />
                        </flux:field>
                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-end">
                        <flux:button
                            wire:click="save"
                            variant="primary"
                            :disabled="$processing || !$uploadFile"
                        >
                            @if($processing)
                                <flux:icon name="arrow-path" class="w-4 h-4 animate-spin mr-2" />
                                Uploading...
                            @else
                                Upload
                            @endif
                        </flux:button>
                    </div>
                </div>
            </flux:card>
        </div>

        {{-- Info Panel --}}
        <div class="lg:col-span-1">
            <flux:card class="sticky top-4">
                <div class="p-6">
                    <flux:heading size="md" class="mb-4">Upload Info</flux:heading>
                    <div class="space-y-3 text-sm">
                        <div>
                            <flux:text class="font-medium">Guidelines:</flux:text>
                            <ul class="list-disc list-inside text-zinc-600 dark:text-zinc-400 mt-1 space-y-1">
                                <li>Maximum file size: 50MB</li>
                                <li>All file types accepted</li>
                                <li>Duplicate filenames will be renamed automatically</li>
                                <li>Files are stored securely on the server</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </flux:card>
        </div>
    </div>

    {{-- Uploaded Files List --}}
    @if(count($uploadedFiles) > 0)
        <div class="mt-8">
            <flux:heading size="lg" class="mb-4">Uploaded Files</flux:heading>
            <flux:card>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left p-4 font-medium text-zinc-500">Name</th>
                                <th class="text-left p-4 font-medium text-zinc-500">Size</th>
                                <th class="text-left p-4 font-medium text-zinc-500">Uploaded</th>
                                <th class="text-right p-4 font-medium text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($uploadedFiles as $uploaded)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="p-4">
                                        <div class="flex items-center gap-2">
                                            <flux:icon name="document" class="w-4 h-4 text-zinc-400" />
                                            <span>{{ $uploaded['name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="p-4 text-zinc-500">{{ $uploaded['size'] }}</td>
                                    <td class="p-4 text-zinc-500">{{ $uploaded['uploaded_at'] }}</td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <flux:button
                                                wire:click="downloadFile('{{ $uploaded['path'] }}')"
                                                size="sm"
                                                variant="ghost"
                                                icon="arrow-down-tray"
                                                title="Download"
                                            />
                                            <flux:button
                                                wire:click="deleteFile('{{ $uploaded['path'] }}')"
                                                wire:confirm="Are you sure you want to delete this file?"
                                                size="sm"
                                                variant="ghost"
                                                icon="trash"
                                                title="Delete"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        </div>
    @endif
</div>
