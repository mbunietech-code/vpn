// Makes the macOS menu-bar icons (template images: black + alpha, so macOS
// tints them for light and dark menu bars) from the brand shield.
//   swift macos/engine-scripts/make-tray-icons.swift   (from client/)
// → assets/tray/mac-on.png (connected), assets/tray/mac-off.png (dimmed)
import AppKit

let src = NSImage(contentsOfFile: "branding/icon-foreground.png")!
var rect = CGRect(origin: .zero, size: src.size)
let cg = src.cgImage(forProposedRect: &rect, context: nil, hints: nil)!
let w = cg.width, h = cg.height

// Full-size mask: the coloured strokes (outline + "M") are opaque; the white
// inside of the shield and the background are clear.
var rgba = [UInt8](repeating: 0, count: w * h * 4)
let rgb = CGColorSpaceCreateDeviceRGB()
let read = CGContext(data: &rgba, width: w, height: h, bitsPerComponent: 8, bytesPerRow: w * 4,
                     space: rgb, bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
read.draw(cg, in: CGRect(x: 0, y: 0, width: w, height: h))
var mask = [UInt8](repeating: 0, count: w * h * 4)
for i in 0 ..< w * h {
    let a = Double(rgba[i * 4 + 3]) / 255
    guard a > 0 else { continue }
    let lightest = Double(min(rgba[i * 4], rgba[i * 4 + 1], rgba[i * 4 + 2])) / 255 / a
    let ink = max(0, min(1, (0.85 - lightest) / 0.35)) * a // white → 0, blue → 1
    mask[i * 4 + 3] = UInt8(ink * 255) // black, premultiplied
}
let full = CGContext(data: &mask, width: w, height: h, bitsPerComponent: 8, bytesPerRow: w * 4,
                     space: rgb, bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!.makeImage()!

func write(_ name: String, alpha: CGFloat) {
    let px = 36 // drawn at 18 pt: sharp on Retina
    let ctx = CGContext(data: nil, width: px, height: px, bitsPerComponent: 8, bytesPerRow: 0,
                        space: rgb, bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
    ctx.interpolationQuality = .high
    ctx.setAlpha(alpha)
    ctx.draw(full, in: CGRect(x: 0, y: 0, width: px, height: px))
    let rep = NSBitmapImageRep(cgImage: ctx.makeImage()!)
    try! rep.representation(using: .png, properties: [:])!.write(to: URL(fileURLWithPath: "assets/tray/\(name)"))
}

write("mac-on.png", alpha: 1)
write("mac-off.png", alpha: 0.45)
print("assets/tray/mac-on.png, assets/tray/mac-off.png")
